# 32. Pokladna

> Návod, jak vést hotovost v MyÚčtu: založit pokladnu, vystavit příjmový (PPD)
> a výdajový (VPD) pokladní doklad, uhradit z pokladny fakturu, opravit chybu
> stornem a vytisknout pokladní knihu. Pro účetní a každého, kdo za firmu
> pracuje s hotovostí. Pokladna je dostupná v podvojném účetnictví i v daňové evidenci.

Pokladnu najdete v menu `Peníze → Pokladna` (hned za položkou Bankovní účty). V podvojném účetnictví se každý doklad promítá do [Účetního deníku](52_Ucetni_denik.md) na účet 211. V daňové evidenci běží pokladna bez účetního deníku (kasová báze).

> [!TIP]
> V podvojném účetnictví může být pokladna vedena v **CZK, EUR, USD nebo GBP**. Valutová pokladna eviduje cizí částku i její korunový ekvivalent a nese cizoměnovou stopu pro závěrkové přecenění. V režimu daňové evidence zůstává pokladna korunová, protože tento režim nemá účetní deník ani analytiky 211.

## 32.1 Kdy to potřebujete

- Firma začíná vést hotovost a potřebuje založit první pokladnu.
- Přijali jste hotovost od zákazníka nebo jste něco koupili za hotové.
- Zákazník zaplatil fakturu hotově, nebo hotově platíte přijatou fakturu.
- Dodavatel vám vrací hotovost za vrácené zboží nebo přeplatek.
- Omylem jste vystavili doklad a potřebujete ho opravit.
- Potřebujete tisk dokladu nebo pokladní knihu pro účetní uzávěrku.
- K dokladu chcete připojit sken účtenky.

<!-- cols: 24 40 36 -->
| Kdy | Co udělat | Kde v aplikaci |
|---|---|---|
| jednou při zavádění | Založit pokladnu | `Peníze → Pokladna`, **…** → **Správa pokladen** |
| při každém pohybu hotovosti | Vystavit PPD nebo VPD | tlačítka **Příjem** a **Výdej** |
| podle potřeby | Uhradit fakturu z pokladny | doklad s účelem **Úhrada vydané faktury** nebo **Úhrada přijaté faktury** |
| při chybě | Stornovat doklad | ikona **Storno dokladu** u řádku |
| měsíčně a ročně | Zkontrolovat zůstatek a vytisknout knihu | **Pokladní kniha**, **PDF knihy** |

## 32.2 Než začnete

1. **Účtová osnova (podvojné účetnictví).** Každá pokladna potřebuje vlastní analytiku účtu 211 (například `211.100`). Chybí-li, založíte ji v osnově, odkaz je přímo u pole účtu (viz [Účtová osnova](66_Ucetni_osnova.md#6684-teckovany-zapis-analytik)).
2. **Číselné řady.** Prefix a tvar čísla `PPD-RRRR-####` a `VPD-RRRR-####` nastavíte v `Nástroje → Číselné řady`, a to v obou účetních režimech.
3. **Oprávnění.** Vystavení dokladů vyžaduje právo zápisu pokladních dokladů. Správu pokladen smí provádět jen ten, kdo má právo je spravovat. Trvalé smazání zaúčtovaného dokladu vyžaduje samostatné právo **Uzavřít pokladnu a trvale mazat doklady** (viz [§ 32.11.11](#321111-omezeni-a-tipy)).
4. **Fakturu k úhradě** vystavte nebo přijměte dřív, než ji budete z pokladny hradit.

## 32.3 Krok za krokem: založení pokladny

Firma může mít libovolný počet pokladen, typicky jednu hlavní, případně další pro pobočku nebo provozovnu.

1. Otevřete `Peníze → Pokladna`, v nabídce **…** klikněte na **Správa pokladen**.
2. V části **Přidat pokladnu** vyplňte **Název** (například „Hlavní pokladna").
3. V podvojném účetnictví zvolte **Měnu pokladny** (CZK, EUR, USD nebo GBP) a **Analytický účet** (analytika účtu 211). U valutové pokladny je účet volitelný: nevyplníte-li ho, aplikace přidělí volný kód 211 a účet v osnově založí.
4. Chcete-li, zaškrtněte **Výchozí** (pokladna se předvybere u nových dokladů) a **Vlastní číselná řada**.
5. Klikněte na **Uložit**.

**Jak poznáte, že je hotovo:** Pokladna je v seznamu s aktuálním zůstatkem a na stránce `Peníze → Pokladna` se zobrazí její zůstatek.

> [!WARNING]
> Každá pokladna musí mít jinou analytiku 211. Dvě pokladny na stejný účet založit nelze, ani když je jedna neaktivní. Měnu zvolte při založení, po vzniku pohybů ji změnit nejde.

Pokladnu s doklady nelze smazat. Dočasně ji vyřadíte odškrtnutím **Aktivní** a uložením. Neaktivní pokladna se v nabídkách pro nové doklady nenabízí, existující doklady zůstanou.

## 32.4 Krok za krokem: příjem nebo výdej hotovosti

Takto vystavíte prodej za hotové (PPD) nebo nákup za hotové (VPD).

1. Otevřete `Peníze → Pokladna`. Při více pokladnách vyberte pokladnu v hlavičce.
2. Klikněte na **Příjem** (zelené) nebo **Výdej** (oranžové).
3. Zkontrolujte **Datum** a vyberte účel **Prodej** (u příjmu) nebo **Nákup** (u výdeje).
4. Vyplňte partnera (IČO a DIČ se doplní z evidence), celkovou částku včetně DPH a **Popis**. V podvojném účetnictví vpravo vidíte živý náhled zaúčtování.
5. Potřebujete-li DPH, přepněte na **S DPH** (viz [§ 32.5](#325-krok-za-krokem-doklad-s-dph)).
6. Klikněte na **Vystavit**. Chcete-li doklad jen rozepsat, klikněte na **Uložit jako koncept**.

U samostatného **Nákupu** v podvojném účetnictví můžete v poli **Nákladový účet** zvolit aktivní účet třídy 5 nebo jeho analytiku, například `518.200`. Náhled zaúčtování se hned změní. Prázdná volba použije výchozí účet z kontace nákupu (obvykle `501`). Při úhradě už zaevidované přijaté faktury použijte **Úhrada přijaté faktury**; náklad se účtuje na faktuře a pokladní úhrada vyrovná závazek `321/211`.

**Jak poznáte, že je hotovo:** Zobrazí se číslo přiděleného dokladu a doklad je v seznamu se stavem **Vystaven**. Číslo se přiděluje až při vystavení. Případná varování (například záporný zůstatek pokladny) se zobrazí hned.

Rozepsaný doklad (**Rozpracován**) nemá číslo ani zaúčtování. Vystavíte ho ikonou **Vystavit** u řádku, upravíte ikonou **Upravit**, případně smažete ikonou **Trvale smazat doklad** (po rozpracovaném dokladu nezůstane stopa a v číselné řadě nevznikne díra).

> [!WARNING]
> U dokladu nad **270 000 Kč** se zobrazí upozornění na zákon č. 254/2004 Sb., o omezení plateb v hotovosti. Platbu nad limit mezi týmiž osobami v jeden den je nutné uhradit bezhotovostně. Doklad se přesto uloží.

## 32.5 Krok za krokem: doklad s DPH

DPH lze zapnout jen u účelů **Prodej** a **Nákup**. U úhrad faktur, převodů a ostatního je DPH vypnuté (u úhrady faktury nese daň sama faktura).

1. V dokladu přepněte na **S DPH**.
2. Zkontrolujte **DUZP** (výchozí shodné s datem vystavení).
3. V rozpadu DPH klikněte na **Přidat sazbu** a zadejte **Sazbu**, **Základ** a **DPH** pro každou sazbu. Součet rozpadu se musí přesně rovnat celkové částce dokladu.
4. U nákupu nastavte na každém řádku rozpadu **Nárok na odpočet DPH** a **Daň z příjmů**. Nakupujete-li dlouhodobý majetek, zaškrtněte **Pořízení dlouhodobého majetku**.
5. Klikněte na **Vystavit**.

**Jak poznáte, že je hotovo:** Doklad se vystaví a v rozbaleném detailu vidíte tabulku rozpadu základ/sazba/daň.

> [!WARNING]
> U korunového nákupu (VPD) s DPH formulář blokuje částku **10 000 Kč včetně DPH a vyšší** (hranice zjednodušeného daňového dokladu). Vyšší částky vedete jako přijatou fakturu a uhradíte ji účelem **Úhrada přijaté faktury**. U prodeje nad 10 000 Kč bez DIČ partnera se zobrazí jen upozornění (nejde o blokaci).

## 32.6 Krok za krokem: úhrada faktury z pokladny

**Vydaná faktura (příjem):**

1. Klikněte na **Příjem** a zvolte účel **Úhrada vydané faktury**.
2. Do pole **Vyberte fakturu** napište číslo nebo partnera a vyberte nezaplacenou fakturu. Partner (název, IČ a DIČ odběratele), popis a částka se předvyplní. Platí-li za odběratele někdo jiný, partnera přepište.
3. Částku můžete snížit na částečnou úhradu (do výše zbývající k úhradě).
4. Klikněte na **Vystavit**.

**Přijatá faktura (výdej):**

1. Klikněte na **Výdej** a zvolte účel **Úhrada přijaté faktury**.
2. Vyberte fakturu. Partner (dodavatel s IČ a DIČ) se předvyplní. Částka je uzamčená: přijatou fakturu hradíte vždy v **plné zbývající výši**.
3. Klikněte na **Vystavit**.

**Vratka od dodavatele (příjem):** Klikněte na **Příjem**, zvolte účel **Úhrada přijaté faktury**, vyberte fakturu, na které už úhrada visí, zadejte vrácenou částku (libovolnou, nejvýše uhrazenou) a doklad vystavte. Když vratku později stornujete nebo smažete a zbývající úhrady kryjí celou fakturu, faktura je znovu uhrazená s datem poslední zbývající úhrady, stejně jako po zrušení spárování bankovní vratky ([§ 29](29_Banka.md)).

**Jak poznáte, že je hotovo:** Faktura je označená jako uhrazená a doklad je v seznamu se stavem **Vystaven**. U zálohové faktury se plátci DPH navíc připraví koncept daňového dokladu k přijaté platbě.

> [!TIP]
> Hotově můžete platit i přímo z editoru faktury volbou způsobu úhrady **Hotově** (viz [§ 32.11.7](#32117-doklad-vznikly-z-faktury-hotovostni-vyrovnani)).

## 32.7 Krok za krokem: storno dokladu

Zaúčtovaný doklad nelze opravit ani smazat. Opravuje se stornem.

1. Na stránce `Peníze → Pokladna` najděte doklad a klikněte na ikonu **Storno dokladu**.
2. Vyplňte **Důvod storna** (nejméně 3 znaky). Pole **Datum** můžete nechat prázdné.
3. Klikněte na **Stornovat**.

**Jak poznáte, že je hotovo:** Doklad je v seznamu přeškrtnutý, má stav **Stornován** a číslo zůstává v řadě obsazené. Zůstatek pokladny se vrátil. Šlo-li o úhradu faktury, faktura se vrátila do předchozího stavu.

> [!WARNING]
> Storno dokladu, který už byl zahrnut do podaného přiznání k DPH nebo kontrolního hlášení, se v podaném přiznání zpětně neopraví. Případný rozdíl řešíte dodatečným přiznáním nebo následným kontrolním hlášením mimo tento systém.

## 32.8 Krok za krokem: pokladní kniha a tisk

1. Pro tisk dokladu klikněte u zaúčtovaného nebo stornovaného dokladu na ikonu **Tisk PDF**.
2. Pro knihu klikněte na `Peníze → Pokladna` na **Pokladní kniha**.
3. Zvolte pokladnu a rozsah **Datum od** a **Datum do** (výchozí je od začátku roku do dneška).
4. Kliknutím na **PDF knihy** vygenerujete tiskovou sestavu.

**Jak poznáte, že je hotovo:** PDF obsahuje hlavičku pokladny, počáteční a konečný zůstatek a přehled příjmů a výdajů. Sestava respektuje zvolené filtry. Hodí se jako podklad k roční uzávěrce.

## 32.9 Krok za krokem: sken účtenky k dokladu

1. Na stránce `Peníze → Pokladna` rozbalte doklad kliknutím na řádek.
2. V sekci **Přílohy** klikněte na **Nahrát sken** (PDF nebo obrázek), nebo na **Připojit dokument** a vyhledejte soubor, který už v Dokumentech je.
3. U přílohy můžete otevřít náhled, stáhnout ji nebo ji odpojit.

**Jak poznáte, že je hotovo:** Příloha je v sekci vidět. Odpojení soubor nemaže, jen zruší vazbu.

## 32.10 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Nelze založit pokladnu, „Analytiku už používá jiná pokladna" | Účet 211 patří jiné pokladně (i neaktivní) | Zvolte jinou analytiku, případně ji založte v osnově. |
| „Pokladnu s doklady nelze smazat – deaktivujte ji." | Pokladna už má doklady | V okně **Správa pokladen** odškrtněte **Aktivní** a uložte. |
| Chybí nabídka Pokladna v menu | Firma nemá podvojné účetnictví ani daňovou evidenci | Pokladna se zobrazuje jen v těchto režimech. |
| Doklad nejde vystavit, červená hláška o součtu | Rozpad DPH se liší od celkové částky | Opravte základ, sazbu nebo částku, aby se součet přesně shodoval. |
| Daňový nákup nad 10 000 Kč se nevystaví | Hranice zjednodušeného dokladu | Zaevidujte ho jako přijatou fakturu a uhraďte účelem **Úhrada přijaté faktury**. |
| Částku přijaté faktury nejde upravit | Přijatá faktura se hradí v plné zbývající výši | Pro částečnou úhradu použijte banku. |
| Přijatá faktura se v nabídce neobjeví | Po záloze a dřívějších úhradách nezbývá nic, nebo jsou shody nad 20 | Upřesněte hledání celým číslem dokladu. |
| „Kurz ČNB pro měnu pokladny k datu dokladu není k dispozici" | Pro daný den není kurz ani v povoleném náhradním okně | Doplňte pole **Kurz** ručně. |
| „Zůstatek pokladny je záporný" | Výdaje převýšily příjmy | Jen varování: zkontrolujte doklady, případně doplňte chybějící příjem. |
| Storno se nepovede, období je uzamčené | Zámek zasahuje i aktuální datum | Posuňte zámek v nastavení účetnictví. Jinak se protizápis datuje do prvního otevřeného data. |
| Storno úhrady zálohy se odmítne | Ze zálohy už vznikl finální doklad nebo daňový doklad k platbě | Nejdřív zrušte navazující doklad (hláška uvádí jeho číslo). |
| Rušení hotovostního vyrovnání faktury se zastaví chybou o období | Zápis je v uzavřeném období nebo za zámkem | Vyřešte pokladní doklad v otevřeném období, pak teprve fakturu (viz [§ 32.11.7](#32117-doklad-vznikly-z-faktury-hotovostni-vyrovnani)). |
| Úhradu cizoměnové faktury z pokladny aplikace odmítne | Pokladna ji neumí | Platbu zaevidujte přes banku. |
| Doklad nejde smazat | Zaúčtovaný doklad se maže jen s právem trvalého mazání | Použijte storno. |

## 32.11 Podrobnosti a pravidla

### 32.11.1 Číselník pokladen

Okno **Správa pokladen** se otevírá z nabídky **…** na stránce pokladny. Tabulka ukazuje u každé pokladny **Název**, **Analytický účet** (s názvem z osnovy), **Zůstatek**, přepínač **Výchozí**, **Vlastní číselná řada** a **Aktivní**. Všechny změny (úprava, nová pokladna, smazání) uložíte jedním tlačítkem **Uložit** ve spodní liště.

- **Výchozí.** Zaškrtnutím se pokladna nastaví jako výchozí pro nové doklady. Při uložení se příznak odebere ostatním pokladnám.
- **Vlastní číselná řada.** Pokladna čísluje PPD/VPD vlastní řadou s vlastním prefixem místo společné řady firmy. Přepnout jde jen v roce, ve kterém pokladna ještě nevydala žádné číslo.
- **Aktivní.** Neaktivní pokladna se v nabídkách pro nové doklady nenabízí, existující doklady zůstávají.
- **Smazání** je možné jen u pokladny **bez jediného dokladu**. Pokud už doklady má, použijte deaktivaci. Ikonou koše se pokladna označí ke smazání, smaže se až uložením.
- **Měna** se volí při založení a po vzniku pohybů ji nelze změnit.
- **Účet.** Nabízejí se jen aktivní účty s prefixem 211. V daňové evidenci se pole s účtem a měnou nezobrazuje (evidence nemá účtovou osnovu ani deník). U valutové pokladny je analytika volitelná; už používanou analytiku aplikace nepřevezme.

Každá pokladna musí mít **jinou analytiku 211** (ani neaktivní pokladna účet neuvolní).

> [!TIP]
> Pokud plánujete pokladnu jen dočasně vyřadit z provozu (například na konci roku), použijte deaktivaci. Smazání je vyhrazené pro omylem založené prázdné pokladny.

### 32.11.2 Hlavní stránka pokladny

Stránka `Peníze → Pokladna` zobrazuje:

- **Zůstatek vybrané pokladny** k aktuálnímu dni, název pokladny a analytický účet. U valutové pokladny je hlavní částka v její měně. Pod ní je korunová **Účetní hodnota** z deníku. Při záporné korunové hodnotě systém zobrazí červené upozornění „Zůstatek pokladny je záporný“. Jde o varování, které další práci neblokuje.
- **Výběr pokladny** (pokud jich firma má víc) v rozbalovací nabídce v hlavičce.
- Tlačítka **Příjem** a **Výdej** (zelené pro příjem, oranžové pro výdej), **Pokladní kniha** a v nabídce **…** položku **Správa pokladen**.

#### 32.11.2.1 Filtry a sloupce seznamu

Nad tabulkou dokladů jsou filtry: rozsah data (**Datum od**, **Datum do**, výchozí je aktuální kalendářní rok), typ dokladu (příjem/výdej), stav (**Rozpracován**, **Vystaven**, **Stornován**) a fulltextové hledání v popisu. Filtry lze uložit jako uložený pohled; zobrazené sloupce a hustotu řádků lze upravit tlačítky Sloupce a Hustota (stejný mechanismus jako v ostatních tabulkových přehledech).

Dostupné sloupce: číslo dokladu, datum, typ (P/V), partner, popis, částka, vazba (odkaz na navázanou fakturu nebo účel dokladu), stav, DUZP, vytvořeno a kdo doklad vytvořil (poslední tři jsou ve výchozím zobrazení skryté).

Kliknutím na řádek se rozbalí detail dokladu: účel platby, IČ/DIČ partnera (jsou-li vyplněné), DUZP, název pokladny a u dokladu s DPH tabulka rozpadu základ/sazba/daň po jednotlivých sazbách. V detailu je také prokliknutí **Zobrazit v deníku** na konkrétní zápis (jen v podvojném účetnictví, daňová evidence deník nemá).

U každého řádku jsou ikony: **Upravit** a **Vystavit** (u rozpracovaného dokladu), **Tisk PDF** (u vystaveného nebo stornovaného), **Storno dokladu** (u zaúčtovaného) a **Trvale smazat doklad**. Stránka podporuje serverové stránkování (50 dokladů na stránku).

#### 32.11.2.2 Přílohy pokladního dokladu

V rozbaleném detailu je sekce **Přílohy** pro sken účtenky, paragonu nebo podepsaného pokladního dokladu. Příloha patří přímo k pokladnímu dokladu, takže ji lze připojit i ke konceptu a funguje stejně v podvojném účetnictví i v daňové evidenci.

- **Nahrát sken**: vybrané soubory (PDF nebo obrázek) se uloží do modulu Dokumenty a rovnou se k dokladu připojí.
- **Připojit dokument**: připojí soubor, který už v Dokumentech je (vyhledání podle názvu nebo obsahu).
- U každé přílohy je **náhled** (otevře soubor v nové záložce), stažení a **odpojení**. Odpojení soubor nemaže, jen zruší vazbu; soubor zůstává v Dokumentech.
- **Zobrazit originál**: ukáže první přílohu ve formátu PDF nebo obrázek přímo vedle detailu dokladu. Stejné tlačítko a sekci **Přílohy** má i formulář úprav rozpracovaného dokladu. U nového dokladu vyberete sken tlačítkem **Přiložit originál** v hlavičce formuláře: náhled se hned otevře vedle formuláře, soubor ale zůstává jen v prohlížeči a do Dokumentů se nahraje a k dokladu připojí až při uložení. Když formulář opustíte bez uložení, nic se nenahraje. Pokud se po uložení originál připojit nepodaří, doklad zůstane uložený a aplikace vás upozorní, ať originál nahrajete v úpravě nebo detailu dokladu. Umístění vpravo nebo pod formulářem přepnete v hlavičce náhledu, šířku změníte tažením rozhraní nebo šipkami na klávesnici a aplikace si volbu pamatuje (podrobně viz [§ 23.11.4](23_Prijate_faktury.md#23114-nahrani-dokladu-drag-and-drop-isdoc-a-limity)).

Obráceně lze pokladní doklad připojit i z detailu dokumentu v sekci **Souvisí s** (hledá se podle čísla dokladu, partnera nebo popisu). Připojit jde jen doklad téže firmy. Při smazání pokladního dokladu se jeho vazby na přílohy zruší, soubory samotné zůstanou v Dokumentech. Sekce se zobrazuje uživatelům s přístupem k modulu Dokumenty; nahrávat a připojovat mohou ti, kdo mají práva nahrát a přesunout dokument.

### 32.11.3 Hlavička dokladu a účel

Formulář nového dokladu se otevírá tlačítkem **Příjem** nebo **Výdej** z hlavní stránky pokladny, případně z rychlé akce v menu (ikona plus u položky Pokladna). Předvyplní se typ dokladu podle zvoleného tlačítka a aktuální nebo výchozí pokladna. Pokladní doklad ale může vzniknout i bez toho, že byste sem vůbec šli: přímo z editoru faktury volbou způsobu úhrady **Hotově** (viz [§ 32.11.7](#32117-doklad-vznikly-z-faktury-hotovostni-vyrovnani)).

Hlavička obsahuje typ dokladu (příjem **P**, výdej **V**), **Pokladnu** (z aktivních) a **Datum** (datum vystavení = datum pokladního pohybu). Číslo dokladu (řada `PPD-RRRR-####` / `VPD-RRRR-####`) se přiděluje **až při vystavení**, ne při rozepsání formuláře. Prefix a tvar čísla se nastavují v **Nástrojích → Číselné řady**, a to v obou účetních režimech (daňové evidenci se řady účetního deníku nenabízejí). Pokladna může mít i víc řad, například `HP` pro hotovostní účtenky vedle PPD pro úhrady faktur: další řadu založíte v číselníku řad ([§ 95.6.1](95_Multi_supplier.md#9561-ciselnik-rad-vic-rad-pro-jeden-druh-dokladu)) a v hlavičce dokladu ji pak vyberete v poli **Číselná řada**. Doklady všech řad jedné pokladny jsou v jedné pokladní knize a tvoří jeden zůstatek.

<!-- cols: 24 76 -->
| Typ | Dostupné účely |
|---|---|
| Příjem (PPD) | Prodej (tržba), Úhrada faktury, Úhrada přijaté faktury (= **vratka**), Převod, Ostatní |
| Výdej (VPD) | Nákup, Úhrada faktury (= **výplata dokladu k vyplacení**, jen se zapnutou volbou), Úhrada přijaté faktury, Převod, Ostatní |

**Výplata dokladu k vyplacení.** Se zapnutou volbou **Povolit vyúčtování s částkou k vyplacení** ([§ 95.5.6](95_Multi_supplier.md#958-krok-za-krokem-kopie-e-mailu-podekovani-za-uhradu-a-vyuctovani-k-vyplaceni)) nabídne VPD s účelem *Úhrada faktury* otevřené dobropisy a faktury se zápornou částkou k úhradě. Vyplácí se vždy celá částka k vrácení, zaúčtuje se MD 311 / D analytika pokladny a doklad se označí jako vyplacený. Storno nebo smazání VPD ho vrátí mezi otevřené. V daňové evidenci jde výplata do peněžního deníku jako záporný příjem.

U **valutové pokladny** jsou záměrně dostupné jen účely, které lze bezpečně zaúčtovat bez saldokontního nebo převodového protějšku: PPD **Prodej/Ostatní** a VPD **Nákup/Ostatní**. Úhrady faktur a převody přes 261 formulář nenabídne.

Podle zvoleného účelu se formulář mění:

- **Prodej / Nákup**: zobrazí se pole partner (s našeptávačem z evidence klientů), IČ a DIČ partnera. Volitelně lze doklad vystavit s DPH. U prodeje formulář připomíná, že existuje-li k prodeji faktura, má se použít úhrada faktury, jinak by DPH vzniklo dvakrát.
- **Úhrada faktury / Úhrada přijaté faktury**: zobrazí se našeptávač nezaplacených dokladů (vyhledávání podle čísla nebo partnera); po výběru dokladu se automaticky předvyplní partner (název, IČ a DIČ odběratele, u přijaté faktury dodavatele), popis a částka. Partnera jde přepsat, například když platí jiná osoba než odběratel. Uložíte-li doklad s prázdným partnerem, doplní se odběratel nebo dodavatel faktury. Při změně účelu se partner vymaže. U úhrady vydané faktury lze uhradit i částečnou částku (do výše zbývající k úhradě). U úhrady přijaté faktury (VPD) je nutné uhradit **celou zbývající částku najednou**, částka je po výběru dokladu uzamčená. Zbývající částkou se rozumí brutto snížené o **už uhrazenou zálohu** a o dřívější úhrady (bankovní i hotovostní); doklad, na kterém po záloze nic nezbývá, se v našeptávači vůbec nenabídne. Po zaúčtování se faktura označí jako uhrazená. U přijaté hotovostní úhrady zálohové faktury se stejně jako u bankovní platby automaticky připraví koncept daňového dokladu k částečné platbě, nebo koncept finální faktury při úplném uhrazení zálohy; v našeptávači jsou proto i **zálohové (proforma) faktury**, označené jako záloha. Našeptávač zobrazuje nejvýše 20 shod. Pokud jich odpovídá víc, dá to na konci seznamu vědět poznámkou, upřesněte hledání (například celým číslem dokladu).
- **Převod** (v nabídce **Převod banka ↔ pokladna**): pokladní strana převodu hotovosti mezi bankou a pokladnou. Druhá strana (bankovní strana přes účet 261) vznikne z bankovního výpisu nebo ručním zápisem.
- **Ostatní**: pro případy, které nespadají pod žádný z předchozích účelů (například manko nebo přebytek pokladny). Pole **Co to je** nabízí předvolby, které protiúčet doplní podle kontace. Nesedí-li žádná, zvolte **Jiné - vyberu protiúčet ručně** a vyberte volný protiúčet z účtové osnovy (musí být jiný než účet vybrané pokladny). Protiúčet se vybírá polem s hledáním podle čísla účtu nebo části názvu. DPH u tohoto účelu není podporována.

### 32.11.4 DPH na pokladním dokladu

DPH lze zapnout pouze u účelů **Prodej** a **Nákup** (u úhrad faktur, převodů a ostatního je DPH vynuceně vypnuté, u úhrady faktury DPH nese už samotná faktura). Po přepnutí na **S DPH** se zpřístupní:

- **DUZP**, výchozí shodné s datem vystavení.
- **Rozpad DPH podle sazeb**: řádky se sazbou (nabízí se aktuální sazby větší než 0 z číselníku sazeb pro daný rok), základem a daní; celková částka rozpadu se musí **přesně** rovnat celkové částce dokladu.
- **Nárok na odpočet DPH a daň z příjmů**: jen u účelu **Nákup**, zadávají se na každý řádek rozpadu zvlášť. „Krácený nárok (poměrný §75)“ zpřístupní procento, kterým se odpočet zkrátí; „Krácený koeficientem (§76)“ bere roční koeficient nastavený za celou firmu. Volba daně z příjmů (**Daňově uznatelný náklad** / **Daňově neuznatelný náklad** / **Není náklad**) ovlivňuje jen DPFO/DPPO, na DPH nemá vliv. Výchozí stav je plný nárok a daňově uznatelný náklad.

  Volba se promítá do **výkazů DPH** stejně jako u přijaté faktury: výdajový doklad označený „bez nároku na odpočet" (reprezentace, osobní spotřeba) do Knihy DPH, přiznání ani kontrolního hlášení nevstoupí, krácený nárok § 75 se uplatní zadaným procentem a § 76 se vykáže ve sloupci „Krácený odpočet“. U **příjmového** dokladu je to tržba, na kterou se nárok na odpočet nevztahuje, tam se nastavení neuplatní.
- **Pořízení dlouhodobého majetku**: zaškrtávátko na řádku rozpadu, opět jen u účelu **Nákup**. Označí tu část základu, která připadá na majetek vymezený v § 4 odst. 4 (vozidlo, stroj); hodnota se v přiznání uvede navíc na **ř. 47** jako doplňující údaj, odpočet zůstává na ř. 40/41. Příznak je na řádku, ne na dokladu, protože jeden výdajový doklad běžně kombinuje pořízení majetku s drobným nákupem v jiné sazbě. U příjmového dokladu se nenabízí.

**Rozpad DPH ve valutové pokladně se zadává v měně pokladny.** Uloží se v korunách přepočtený kurzem dokladu, ale formulář ho při otevření rozpracovaného dokladu převede zpět, takže součet vždy sedí na částku v cizí měně.

> [!WARNING]
> U korunového nákupu (VPD) s DPH formulář z bezpečnostních důvodů blokuje částku **10 000 Kč včetně DPH a vyšší** (hranice zjednodušeného daňového dokladu). Od této částky zobrazí upozornění a doklad nelze zaúčtovat; vyšší částky je nutné vést jako přijatou fakturu a uhradit ji účelem „Úhrada přijaté faktury“. U valutového nákupu se tento korunový limit ve formuláři automaticky neposuzuje; použitelnost zjednodušeného dokladu je proto nutné zkontrolovat ručně podle CZK protihodnoty. U prodeje nad 10 000 Kč bez vyplněného DIČ partnera se zobrazí jen informativní upozornění (nejde o blokaci) kvůli evidenci pro kontrolní hlášení.

**DIČ protistrany patří do kontrolního hlášení jen v českém tvaru.** Pokladní prodej se zadanou českou sazbou DPH je tuzemské zdanitelné plnění (místo plnění je pult), a to platí i ve valutové pokladně, protože cizí měna sama o sobě daňový režim nemění. Nad prahem 10 000 Kč proto míří do oddílu A.4, kam patří výhradně české DIČ. Zadáte-li cizí VAT ID (například `DE123456789`), systém na to upozorní; buď je opravte, nebo pole nechte prázdné, doklad pak spadne do sumačního oddílu A.5. Nejde o blokaci.

### 32.11.5 Částka, popis a náhled zaúčtování

Povinná pole jsou **celková částka včetně DPH** (u úhrady přijaté faktury uzamčená) a **popis** (obsah účetního případu). V podvojném účetnictví se vpravo zobrazuje **živý náhled zaúčtování**: tabulka MD/D podle zvoleného účelu a částky, s účty a jejich názvy z osnovy. V daňové evidenci se náhled nezobrazuje (evidence žádný deníkový zápis nevytváří).

Ve valutové pokladně zadáváte částku v měně pokladny. Systém použije kurz ČNB k datu dokladu; není-li pro daný den dostupný, použije dostupný kurz v povoleném náhradním okně, jinak uložení odmítne. Pole **Kurz** lze vyplnit ručně (prázdné = kurz ČNB k datu vystavení); liší-li se od denního kurzu ČNB, zobrazí se upozornění. Do deníku se uloží korunový ekvivalent, zatímco na řádku 211 zůstane částka v cizí měně a použitý kurz. U rozpisu DPH zadáváte základ a daň v měně pokladny; jednotlivé řádky se převedou do CZK a jejich korunový součet musí přesně odpovídat zaúčtované částce.

Doklad uložíte dvojím způsobem. **Vystavit** doklad rovnou zaúčtuje a přidělí mu číslo. **Uložit jako koncept** doklad jen uloží jako **Rozpracován**, bez čísla a bez zaúčtování. U nového dokladu je navíc **Vystavit a další**: doklad vystaví a hned otevře nový prázdný doklad ve stejné pokladně, se stejným typem (příjem nebo výdej) a datem, takže více dokladů za sebou zapíšete bez návratu na seznam. Po vystavení se zobrazí číslo přiděleného dokladu a případná varování (například záporný zůstatek pokladny po zaúčtování).

> [!WARNING]
> **Limit plateb v hotovosti.** U dokladu nad **270 000 Kč** se zobrazí upozornění na zákon č. 254/2004 Sb., o omezení plateb v hotovosti. Platbu nad tento limit provedenou mezi týmiž osobami v jeden den je nutné uhradit bezhotovostně. Nejde o blokaci: doklad se uloží i zaúčtuje, upozornění jen připomíná povinnost plátce.

### 32.11.6 Storno dokladu

Ručně vystavený zaúčtovaný doklad nelze opravit ani smazat; jedinou cestou k opravě je **storno** (ikona zpětné šipky u řádku v seznamu). Storno vyžaduje:

- **Důvod storna**: povinné textové pole (minimálně 3 znaky), vloží se do popisu protizápisu.
- **Datum**: volitelné. Necháte-li je prázdné, protizápis se zaúčtuje **k datu původního zápisu**, takže oprava zůstane ve stejném období jako doklad. Na dnešní datum se posune jen tehdy, když je původní období uzamčené.

Po potvrzení systém vytvoří zrcadlový protizápis, doklad se označí jako stornovaný (v seznamu přeškrtnutý a ztlumený) a číslo dokladu zůstává v řadě obsazené (nedorovnává se). Pokud šlo o úhradu faktury, storno zruší i příslušný záznam úhrady a stav faktury/přijaté faktury se vrátí do předchozího stavu.

Doklad v **uzamčeném období** stornovat lze: protizápis se automaticky posune do prvního otevřeného data. Odmítne se jen tehdy, když zámek zasahuje i aktuální datum; pak je nutné nejdřív posunout zámek v nastavení účetnictví. O posunu data protizápisu systém informuje upozorněním hned po stornu, aby se rozdíl nezjistil až z deníku.

Druhé upozornění přijde, pokud je pokladna **po stornu v mínusu**, typicky když se stornuje příjmový doklad, ze kterého už byly vydané další výdajové doklady. Storno se tím nezastaví, ale je to signál, že navazující doklady je potřeba projít.

Storno **úhrady zálohové faktury**, ze které už vznikla finální faktura nebo daňový doklad k přijaté platbě, systém odmítne, takový doklad by po sobě zůstal vystavený. Zrušte proto nejdřív navazující doklad, teprve pak pokladní doklad; hláška uvede jeho číslo.

**Trvalé smazání** zaúčtovaného dokladu (na rozdíl od storna po sobě nenechá žádnou stopu a v číselné řadě zůstane díra, číslo se znovu nepoužije) vyžaduje samostatné právo **Uzavřít pokladnu a trvale mazat doklady**. Smazat lze jen doklad v otevřeném a neuzamčeném období. Pro doklady, které už prošly přiznáním, použijte raději storno.

### 32.11.7 Doklad vzniklý z faktury (hotovostní vyrovnání)

Pokladní doklad nemusí vzniknout jen tady. Zvolíte-li v editoru [vydané](15_Faktura_editor.md#1592-hlavicka) nebo [přijaté faktury](23_Prijate_faktury.md#231113-zpusob-uhrady-a-platba-hotove-z-pokladny) způsob úhrady **Hotově** a k tomu **pokladnu**, systém při vystavení (resp. uložení či přijetí) sám vystaví a zaúčtuje PPD nebo VPD s účelem *Úhrada faktury*, přesně takový, jaký byste tady vyplnili ručně. Faktura se tím stane uhrazenou.

Takový doklad se od ručního liší v jediné věci: **řídí ho faktura**.

- Zrušíte-li volbu v editoru faktury, doklad se **stornuje protizápisem** a evidovaná úhrada zmizí. Doklad i protizápis zůstávají v evidenci a v pokladní knize, aby číselná řada zůstala souvislá.
- Změníte-li na faktuře pokladnu, původní doklad se stornuje a v nové pokladně vznikne nový s **novým číslem** z její řady.
- Změna částky nebo data vystavení faktury se propíše stejně: starý doklad se stornuje a vznikne nový.
- U přijaté faktury se vyrovnává **zbytek k úhradě**, tedy brutto snížené o už uhrazenou zálohu a o dřívější úhrady. Doklad, na kterém po záloze nic nezbývá, se hotovostně nevyrovnává.
- **Ručně pořízeného dokladu se tenhle mechanismus nikdy nedotkne**, ani když je navázaný na tutéž fakturu. Rozlišují se v datech.

> [!WARNING]
> Rušit vyrovnání jde jen v **otevřeném účetním období** a mimo zámek účtování k datu. Jinak se pokus zastaví chybou „Účetní zápis dokladu je v období „…" - smazat lze jen doklad v otevřeném období.“, resp. „Daňové období je uzamčené; pokladní doklad v něm nelze zaúčtovat ani stornovat.“ Kvůli tomu pak nejde stornovat ani samotná faktura, nejdřív je potřeba vyřešit pokladní doklad.

Doklad vzniklý z faktury **nemá vlastní rozpad DPH**, daň nese sama faktura a úhrada ji neduplikuje. Zaúčtuje se tedy jen dvouřádkově, saldokonto proti pokladně (viz [§ 32.11.10](#321110-zauctovani-a-vazba-na-denik)). Analytika 211 se i tady bere z karty zvolené pokladny.

Hotovostní vyrovnání **nefunguje** u zálohových (proforma) faktur, u pravidelně a hromadně vystavovaných faktur, u cizoměnových dokladů a u valutových pokladen; seznam s vysvětlením je v [§ 15.2.7](15_Faktura_editor.md#1592-hlavicka). Zálohu inkasovanou v hotovosti tedy pořiďte rovnou tady, účelem *Úhrada faktury*; pokladna umí i navazující daňový doklad k platbě.

### 32.11.8 Tisk pokladního dokladu

Ikona **Tisk PDF** u řádku v seznamu otevře PDF verzi dokladu (příjmový/výdajový pokladní doklad s označením PPD/VPD, číslem, údaji firmy, partnerem, datem vystavení a DUZP, částkou, účelem platby, rozpadem DPH u daňových dokladů a podpisovými bloky Vystavil/Schválil/Pokladník/Příjemce). Tisk je dostupný jen u dokladů, které už byly zaúčtovány (případně stornovány); u rozpracovaného dokladu nedává tisk smysl. Tisk je dostupný v obou účetních režimech.

U úhrady faktury uloženého bez partnera uvede PDF jako partnera odběratele, resp. dodavatele placené faktury. Uložený doklad se tím nemění.

U valutového dokladu PDF uvádí původní částku a měnu, korunový ekvivalent i použitý kurz. Tyto údaje před archivací zkontrolujte.

### 32.11.9 Pokladní kniha

Stránka **Pokladní kniha** (tlačítko na hlavní stránce pokladny) zobrazuje chronologický přehled všech pohybů na vybrané pokladně za zvolené období, obdobu výpisu z bankovního účtu, jen pro hotovost.

Nahoře lze zvolit pokladnu (pokud jich firma má víc), rozsah data (výchozí je od začátku kalendářního roku do dneška), typ dokladu (příjem/výdej), účel a fulltextové hledání přes popis, partnera a číslo dokladu. Tlačítkem **Zrušit filtry** se vrátí výchozí nastavení.

> [!TIP]
> Filtry zužují jen **vypsané řádky**. Počáteční a konečný zůstatek i obraty se počítají vždy za celé zvolené období, ne za výběr, jinak by kniha ukazovala zůstatek, který ve skutečnosti nikdy neplatil. Když je nějaký filtr aktivní, připomene to informační pruh nad tabulkou.

Čtyři souhrnné karty ukazují:

- **Počáteční zůstatek** k začátku období,
- **Příjmy celkem** za období,
- **Výdaje celkem** za období,
- **Konečný zůstatek** ke konci období (červeně, pokud je záporný).

U valutové pokladny jsou souhrnné karty a deníkové pohyby v korunové účetní hodnotě. Množstevní zůstatek v měně pokladny je vidět v přehledu pokladen; PPD/VPD a jejich PDF nesou obě hodnoty. Pokud je zůstatek kdekoli v zobrazeném období záporný, nad tabulkou se zobrazí výstražný pruh.

Řádky obsahují datum, číslo dokladu (proklik zpět na doklad v seznamu), typ (P/V), partnera, popis, účel (skrytý ve výchozím zobrazení), DUZP (skrytý), odkaz na zápis v deníku (skrytý, jen podvojné účetnictví), příjem, výdej a průběžný zůstatek po každém řádku. První řádek tabulky vždy zobrazuje počáteční zůstatek období. Sloupce a hustotu řádků lze upravit stejně jako u ostatních tabulek v aplikaci.

Zdrojem pravdy pro zůstatek je vždy **účetní kniha** (zaúčtované obraty na analytice pokladny), nikoli součet pokladních dokladů. Pokud by na účet pokladny vznikl i ruční zápis mimo pokladní doklady, projeví se v knize s prázdným popisem vazby, ale zůstatek zůstává konzistentní.

Tlačítko **PDF knihy** vygeneruje tiskovou sestavu za celý zvolený rozsah data (bez stránkování, ale s uplatněnými filtry, aby odpovídala obrazovce) s hlavičkou pokladny, počátečním a konečným zůstatkem a přehledem příjmů a výdajů. Je vhodné jako podklad k roční uzávěrce nebo pro kontrolu a funguje v podvojném účetnictví i v daňové evidenci.

> [!TIP]
> Přehled hotovostních pohybů za delší období (například pro účetní uzávěrku) získáte exportem PDF z pokladní knihy: obsahuje kompletní chronologický přehled včetně počátečního a konečného zůstatku bez stránkování.

### 32.11.10 Zaúčtování a vazba na deník

V podvojném účetnictví se každý zaúčtovaný pokladní doklad promítá standardním způsobem do [Účetního deníku](52_Ucetni_denik.md). Konkrétní účtovací předpis (MD/D) se liší podle účelu dokladu:

| Účel | Zaúčtování (zjednodušeně) |
|---|---|
| Prodej (PPD) | MD Pokladna (211) / D Tržby (602) + DPH na **343.200** (výstup) |
| Nákup (VPD) | MD Náklad (501) + DPH na **343.100** (vstup) / D Pokladna (211) |
| Úhrada vydané faktury | MD Pokladna (211) / D Pohledávky (311) |
| Úhrada vydané zálohové faktury | MD Pokladna (211) / D Přijaté zálohy (324) |
| Úhrada přijaté faktury (VPD) | MD Závazky (321) / D Pokladna (211) |
| Vratka úhrady přijaté faktury (PPD) | MD Pokladna (211) / D Závazky (321) |
| Převod - příjem z banky | MD Pokladna (211) / D Převody mezi účty (261) |
| Převod - odvod do banky | MD Převody mezi účty (261) / D Pokladna (211) |
| Ostatní | volný protiúčet podle zvoleného účtu |

Firmy s analytikami DPH ([§ 66.8.5](66_Ucetni_osnova.md#6685-analytiky-dph-343100-343200-a-343900)) účtují daň z pokladních dokladů na **343.100** (vstup) a **343.200** (výstup), takže hotovostní doklady vstupují i do měsíčního zúčtování DPH ([§ 66.8.6](66_Ucetni_osnova.md#6686-mesicni-zuctovani-dph)). Firma, která analytiky nemá, účtuje na syntetický účet **343**.

Strana účtu 211 v zápisu vždy odpovídá konkrétní analytice zvolené pokladny. Z detailu dokladu i z řádku pokladní knihy lze prokliknout přímo na odpovídající zápis v deníku. V daňové evidenci žádný deníkový zápis nevzniká, pokladní pohyb se eviduje jen v rámci pokladny samotné (kasová báze).

U valutové pokladny jsou všechny deníkové řádky vedené v CZK a řádek analytiky 211 navíc obsahuje měnu, kurz a cizí částku. Díky tomu ji závěrková kontrola kurzových pozic zahrne mezi účty k přecenění. Samotné PPD/VPD typu Prodej, Nákup a Ostatní se zaúčtují automaticky stejně jako korunové doklady, včetně rozpadu DPH; nejde o pouhý evidenční záznam čekající na ruční deník.

**Vratka úhrady.** Účel **Úhrada přijaté faktury** na *příjmovém* dokladu (PPD) znamená, že dodavatel vrací hotovost, za vrácené zboží nebo přeplatek. Účtuje se opačným směrem (MD 211 / D 321, u zálohové faktury MD 211 / D 314), vrací se **libovolná část** (na rozdíl od úhrady, která musí být v plné výši) a nesmí přesáhnout to, co je na faktuře zaplaceno, jinak by na účtu 321 vznikl debetní zůstatek. Našeptávač v tomhle režimu nabízí faktury, na kterých už úhrada visí, ne ty nezaplacené. Pokud vratka odkryje neuhrazenou část, faktura se vrátí ze stavu *uhrazena*; v peněžním deníku vratka **snižuje daňový výdaj**.

**Zálohová přijatá faktura.** Pokud u účelu **Úhrada přijaté faktury** vyberete zálohovou (proforma) přijatou fakturu, zaúčtuje se místo saldokonta 321 jako **poskytnutá záloha: MD 314 Poskytnuté zálohy / D 211 Pokladna**. Když později zaúčtujete vyúčtovací fakturu vázanou na tuto zálohu, zápis automaticky doplní i zúčtovací řádek (321/314) ve výši skutečně zaplacené zálohy, viz [Přijaté faktury § 23.11.16](23_Prijate_faktury.md#231116-propojeni-zalohy-s-vyuctovaci-fakturou-proti-dvojimu-zapocteni).

### 32.11.11 Omezení a tipy

- Valutová pokladna je dostupná jen v podvojném účetnictví. Podporuje samostatný hotovostní Prodej, Nákup a Ostatní, nikoli úhradu cizoměnové vydané/přijaté faktury. Ta vyžaduje saldokontní vypořádání 311/321 v cizí měně a systém ji záměrně odmítne místo vytvoření neúplného zápisu.
- Převod mezi valutovou pokladnou a bankou nebo jinou pokladnou přes účet 261 není podporovaný. Různé měny vyžadují doložený kurz a kurzový rozdíl; zaúčtujte je ručně v deníku.
- Úhradu přijaté faktury z pokladny lze provést jen v plné zbývající výši; částečné úhrady přijatých faktur hotově systém nepodporuje. Zbývající výše je brutto snížené o uhrazenou zálohu a o dřívější úhrady.
- Trvalé smazání zaúčtovaného dokladu vyžaduje samostatné právo **Uzavřít pokladnu a trvale mazat doklady**. Běžné právo na pokladní doklady na to nestačí; standardní cestou k opravě je storno.
- Z korunové pokladny lze uhradit jen korunovou fakturu; z valutové pokladny není účel úhrady faktury dostupný ani pro fakturu ve stejné měně.
- Korunový daňový doklad při nákupu (VPD) formulář blokuje od 10 000 Kč včetně DPH. U valutového VPD kontrolujte limit ručně podle korunové protihodnoty.
- Vystavený doklad se needituje ani nemaže, jedinou opravou je storno s uvedením důvodu a případné vystavení nového dokladu. Výjimkou je rozpracovaný doklad (lze ho upravit i smazat) a doklad vzniklý z faktury ([§ 32.11.7](#32117-doklad-vznikly-z-faktury-hotovostni-vyrovnani)), který faktura umí zase zrušit.
- Hotovostní vyrovnání z editoru faktury neumí zálohové faktury, pravidelné a hromadně vystavované faktury ani valutové pokladny; ty se hradí ručním dokladem tady.
- Smazat lze jen pokladnu bez jediného dokladu; jinak nabídne systém deaktivaci.

## 32.12 Související kapitoly

- [Účetní deník](52_Ucetni_denik.md) - zápisy pokladních dokladů.
- [Vydané faktury, editor](15_Faktura_editor.md) - způsob úhrady Hotově.
- [Přijaté faktury](23_Prijate_faktury.md) - hotovostní úhrada přijatých faktur.
- [Účtová osnova](66_Ucetni_osnova.md) - analytiky 211 a analytiky DPH.
- [Banka](29_Banka.md) - převody mezi bankou a pokladnou.
