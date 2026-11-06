# 60. Saldokonto

> Porovnání otevřených položek podle partnerů se zůstatkem saldokontního účtu v hlavní knize k vybranému dni. Podklad pro inventarizaci pohledávek, závazků a záloh. Kapitola je pro účetní v podvojném účetnictví.

## 60.1 Kdy to potřebujete

Kapitolu otevřete, když:

- se blíží konec měsíce nebo roku a potřebujete ověřit účty 311, 321, 314, 324, 315 a 325,
- chcete zjistit, kolik vám dluží odběratelé nebo kolik dlužíte dodavatelům k určitému dni,
- potřebujete rozhodnout, co poslat na [upomínku](22_Upominky.md) nebo na [zápočet](67_Zapocty.md),
- zůstatek účtu v hlavní knize nesedí na doklady,
- připravujete inventarizační protokol (navazuje na [Inventarizaci účtů](69_Inventarizace_rozvahovych_uctu.md)),
- chcete z otevřeného roku nahlédnout na 31. 12. loňska a porovnat počáteční stav nového roku.

## 60.2 Než začnete

1. **Podvojné účetnictví.** Saldokonto je dostupné jen firmám v podvojném účetnictví.
2. **Zaúčtované doklady.** Otevřené položky vznikají z dokladů navázaných na účetní zápis. Nezaúčtované doklady dořešte v [K doúčtování](54_Rucni_fronta_doctovani.md).
3. **Zaúčtované úhrady.** Pozdější úhrada nezmění historické saldokonto, platí datum platby.

## 60.3 Krok za krokem: kontrola saldokonta k rozvahovému dni

1. Otevřete `Účetnictví → Saldokonto`.
2. V poli **Období** zvolte účetní období a v poli **Rozvahový den** datum. Bez data se použije dřívější z posledního dne období a dneška.
3. V poli **Účet** zvolte **Vše (311/321/314/324/315/325)**, nebo jeden účet.
4. Podívejte se na konfrontační pruh: **Zůstatek účtu (hlavní kniha)**, **Σ otevřených položek** a **Rozdíl**.
5. Je-li rozdíl nulový, účet je v pořádku. Nenulový rozdíl řešte podle [§ 60.4](#604-krok-za-krokem-dohledani-rozdilu).
6. Přepněte zobrazení: **Podle dokladů** (plochý seznam, výchozí) nebo **Podle partnera** (rozbalením partnera uvidíte jeho doklady).
7. V pohledu **Podle dokladů** můžete zúžit seznam filtrem **Partner** a **Po splatnosti (dní, min.)**. Sloupce řadíte kliknutím na záhlaví.
8. Klikněte na **Export PDF** (inventarizační protokol) nebo **Export XLSX**.

**Jak poznáte, že je hotovo:** **Rozdíl** je nulový na všech kontrolovaných účtech a protokol je vyexportovaný.

### Podklad pro banku: pohledávky podle doby po splatnosti

1. V poli **Účet** zvolte účet, který banka chce vidět, typicky **311**.
2. Pod filtry zaškrtněte **PDF s členěním podle doby po splatnosti**.
3. Do pole **Hranice (dny)** zapište rozmezí, která banka požaduje, například `30, 60, 120`.
4. Chcete-li jen souhrnnou tabulku bez jednotlivých dokladů, zrušte **Včetně rozpisu dokladů**.
5. Klikněte na **Export PDF**.

Aplikace si volbu i hranice zapamatuje a nabídne je i příště.

> [!WARNING]
> Nulový rozdíl sám nepotvrzuje existenci pohledávky, vymahatelnost ani úplnost závazků. Tyto skutečnosti je nutné doložit nezávislými podklady.

## 60.4 Krok za krokem: dohledání rozdílu

1. Přepněte na pohled **Podle partnera** a najděte partnera, u kterého částky nesedí.
2. Rozbalte partnera. Kliknutím na doklad otevřete detail vydané nebo přijaté faktury.
3. Zkontrolujte úhrady, částečné platby, dobropisy, zápočty a storna.
4. U ručního nebo kurzového zápisu pokračujte do opisu účtu v [Hlavní knize](55_Hlavni_kniha.md).
5. Opravte příčinu (zaúčtujte úhradu, opravte datum platby, stornujte chybný ruční zápis) a sestavu načtěte znovu.

**Jak poznáte, že je hotovo:** **Rozdíl** je nulový, nebo je zbývající rozdíl vysvětlený a doložený.

Nenulový rozdíl může ukazovat na ruční zápis bez vazby na doklad, chybějící či špatně datovanou úhradu, storno, kurzový pohyb nebo nezúčtovanou zálohu.

### Vyrovnání položky zápisem v deníku

Doklad zůstává otevřený, přestože ho hlavní kniha má vyrovnaný interním dokladem: zápočet přes 395, interní doklad vyrovnávající víc faktur, odpis. Typicky po převodu z jiného programu. Zápis v deníku už existuje, jen ho přiřadíte dokladům:

1. U otevřené položky klikněte na **Vyrovnat**. Víc položek jednoho partnera na jednom účtu označte zaškrtávátky a klikněte na **Vyrovnat označené**.
2. Na kartě **Spárovat se zápisem v deníku** jsou volné ruční zápisy na stejném účtu (včetně analytik) do rozvahového dne. Nahoře jsou zápisy se stejnou částkou, s číslem dokladu v textu a se stejným partnerem. Jistá shoda je předvyplněná.
3. U jednoho dokladu můžete vybrat víc zápisů a upravit částky. U víc dokladů vyberete jeden zápis a jeho částku rozdělíte mezi doklady.
4. Klikněte na **Vyrovnat**. Doklad je vyrovnaný ke dni zápisu a **Rozdíl** klesne o přiřazenou částku. Hlavní kniha ani předvaha se nemění.

Na kartě **Zápočet / odpis** založíte zápočet dokladu proti zvolenému účtu, který zápis v deníku teprve vytvoří (viz [Zápočty](67_Zapocty.md)), nebo tlačítkem **Vzájemný zápočet s partnerem** otevřete stránku Zápočty předvyplněnou partnerem a dokladem. Přiřazení zrušíte v dialogu **Vyrovnat** u seznamu **Už přiřazené zápisy**.

### Vzájemný zápočet z výběru

Když partner na pohledávkové straně (311, 315) i závazkové straně (321, 325) má otevřené faktury v Kč, označte v pohledu **Podle dokladů** zaškrtávátky jeho pohledávky i závazky a klikněte na **Vzájemný zápočet**. Otevře se stránka [Zápočty](67_Zapocty.md#673-krok-za-krokem-sestavit-a-potvrdit-zapocet) s vybraným partnerem a doklady. Částky jsou vyrovnané na menší ze stran: větší strana se rozdělí mezi doklady v pořadí výběru, každému nejvýš jeho zbytek. Tlačítko se zobrazí jen s oprávněním k vytváření zápočtů.

### Vyrovnání zálohy konečnou fakturou

Záloha na **314** nebo **324** zůstává otevřená, přestože ji konečná faktura v hlavní knize odečetla: vyúčtování energií odečítá víc měsíčních záloh najednou, nájem čerpá kauci po částech, nebo faktura nemá zálohu navázanou. Zálohu nevyrovnává zápis v deníku, ale konečná faktura, takže ji přiřadíte faktuře:

1. U otevřené zálohy klikněte na **Vyrovnat**. Víc záloh jednoho partnera na jednom účtu označte zaškrtávátky a klikněte na **Vyrovnat označené**.
2. Dialog nabídne konečné faktury téhož partnera zaúčtované do rozvahového dne, které mají na zálohovém účtu odpočet zálohy (přijatá faktura na straně Dal 314, vydaná na straně MD 324, včetně analytik). Sloupec **Nepřiřazeno** ukazuje část odpočtu, kterou ještě nečerpá jiná záloha. Nahoře jsou faktury s odpočtem rovným zbytku zálohy, s číslem zálohy v textu faktury a vystavené po záloze. Jediná shoda částky je předvyplněná.
3. U jedné zálohy můžete vybrat víc faktur a upravit částky. U víc záloh vyberete jednu fakturu a její odpočet rozdělíte mezi zálohy.
4. Klikněte na **Vyrovnat čerpáním**. Záloha je vyrovnaná ode dne zaúčtování faktury a **Rozdíl** klesne o čerpanou částku. Hlavní kniha ani předvaha se nemění.

Čerpání zadané v saldokontu zrušíte v dialogu **Vyrovnat** u seznamu **Čerpání zálohy**. Čerpání převzaté z převodu z jiného programu je označené **z převodu** a v saldokontu se neruší. Faktura navázaná na zálohu přímo na dokladu (vyúčtovací faktura k zálohové faktuře, konečná faktura z proformy) přiřazuje svůj odpočet té záloze do výše její částky; jiné záloze se nabídne jen přebytek odpočtu nad ní.

Záloha čerpaná v saldokontu se už nedá propojit s fakturou na dokladu (ani vystavit konečnou fakturu z proformy) a faktura, která zálohu čerpá, se nedá propojit s další zálohou: vazba by čerpání zdvojila. Zbytek takové zálohy vyrovnejte v saldokontu. Mimo saldokonto se čerpaná záloha chová jako vyúčtovaná (nepočítá se do pohledávek ani nákladů).

Když se konečná faktura po čerpání přeúčtuje s menším odpočtem zálohy (nebo převod zapsal čerpání nad rámec zápisu), ukáže blok účtu varování s fakturou a přečerpanou částkou. Čerpání pak upravte v dialogu **Vyrovnat**, nebo opravte zaúčtování faktury.

### Položka ze zůstatku bez dokladu

Zůstatek účtu bez dokladu (přijatá nebo poskytnutá záloha, přeplatek zaúčtovaný interním dokladem) převedete na ostatní pohledávku nebo závazek:

1. U účtu s nenulovým **Rozdílem** klikněte na **Založit položku ze zůstatku**.
2. Vyberte zápis, ze kterého zůstatek vznikl. Hledat můžete podle textu nebo čísla zápisu.
3. Upravte popis, částku, splatnost a případně partnera a klikněte na **Založit položku**.

Vznikne potvrzená [ostatní pohledávka nebo závazek](52_Ucetni_denik.md#521414-ostatni-pohledavky-a-zavazky) s číslem z řady. Zápis na straně MD založí pohledávku, zápis na straně Dal závazek. Položka se znovu neúčtuje, předpis zůstává v původním zápisu, takže saldokonto položku vidí a zůstatek hlavní knihy nezdvojí. Omyl napravíte stornem položky: protizápis nevznikne, položka zmizí ze saldokonta ode dne storna a ten den musí ležet v otevřeném účetním období.

### Návrh párování

Tlačítko **Navrhnout párování** projde otevřené doklady k rozvahovému dni a nabídne jen jisté shody: číslo dokladu je v textu zápisu (nebo je zápis na doklad navázaný) a volná částka zápisu se rovná zbytku dokladu, případně zápis jmenuje víc dokladů jednoho partnera přesně za jejich součet. Bez čísla dokladu se navrhne jen jistý partner (vazba zápisu nebo jeho název v textu) s jediným dokladem této částky. Zápis i doklad jsou nejvýš v jednom návrhu. Označené návrhy potvrdíte tlačítkem **Potvrdit označené**; návrh, který mezitím přestal platit (doklad uhrazený), zůstane v seznamu s důvodem.

Na účtech 314 a 324 návrh páruje zálohy s konečnými fakturami téhož partnera: číslo zálohy je v popisu řádku nebo v textu zápisu faktury, nepřiřazený odpočet právě jedné faktury se rovná zbytku zálohy, nebo u pravidelných záloh stejné výše leží mezi zálohou a další zálohou partnera jediná faktura a její odpočet se rovná zbytku zálohy. Faktura, kterou by návrhy přečerpaly, se nenavrhne.

> [!NOTE]
> Čerpání zálohy se vejde do zbytku zálohy i do nepřiřazeného odpočtu faktury. Zálohu v cizí měně čerpáním v saldokontu nevyrovnáte. Faktura zaúčtovaná v uzavřeném období čerpá zálohu jen po potvrzení správce; čerpání změní saldokonto ode dne zaúčtování faktury, hlavní kniha zůstane beze změny.

> [!NOTE]
> Přiřadit lze jen ruční (interní) zápis v Kč na účtech 311, 321, 315 a 325, ne zaúčtování dokladu, storno ani zápis, který doklad vyrovnává už vazbou na doklad. Doklad v cizí měně vyrovnejte kurzovým zápisem nebo zápočtem. Zápis v uzavřeném období přiřadí jen správce po potvrzení; přiřazení změní saldokonto k jeho konci, hlavní kniha zůstane beze změny. Kontrola uzávěrky počítá přiřazení stejně jako saldokonto. Zápis s přiřazením nejde smazat a přeúčtování nesmí přiřazený řádek změnit (účet, stranu, pořadí) ani snížit pod přiřazenou částku; nejdřív přiřazení zrušte. Zápis se zápočtem převzatým z jiného programu se nepřiřazuje, zápočet už saldokonto počítá.

## 60.5 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Hláška, že rozvahový den spadá do jiného období, než je vybrané | Rozvahový den smí ležet mimo vybrané období; sestava si období dohledá sama | Klikněte na **Přepnout na období N**, nebo hlášku ignorujte, sestava je spočtena správně. |
| Hláška, že pro rozvahový den není založené žádné účetní období | Zůstatky se počítají kumulativně od počátku historie | Zkontrolujte datum. Není-li to omyl, nejde o chybu. |
| Server sestavu odmítl s výzvou k zúžení výběru | Sestava zvládne nejvýše 25 000 otevřených položek | Zúžte výběr jedním účtem, jedním partnerem nebo dřívějším rozvahovým dnem. |
| **Pro zvolený rozvahový den nejsou na saldokontních účtech žádné zůstatky ani otevřené položky.** | Účty jsou k tomu dni prázdné | Zkontrolujte **Rozvahový den** a **Účet**. |
| **Zůstatek účtu se neshoduje se součtem otevřených položek** | Ruční zápisy, storna, cizoměnové neúčtované úhrady nebo faktury bez zdrojového dokladu | Postupujte podle [§ 60.4](#604-krok-za-krokem-dohledani-rozdilu). |
| Záloha mezi otevřenými položkami chybí | Plně zúčtovaná záloha zmizí, historické pohyby zůstanou v deníku | Nejde o chybu ([§ 60.6.3](#6063-zalohy-314-a-324)). |
| Jiný účet než šest nabízených nelze vybrat | Běžná obrazovka nabízí jen uvedené účty | Opis jiného účtu najdete v [Hlavní knize](55_Hlavni_kniha.md). |

## 60.6 Podrobnosti a pravidla

### 60.6.1 Období a účty

Vyberte účetní období a **Rozvahový den**. Bez data se použije dřívější z
posledního dne období a dneška. Rozvahový den smí ležet i mimo vybrané
období - třeba když chcete z otevřeného roku nahlédnout na 31. 12. loňska
(i uzavřeného/schváleného), abyste porovnali počáteční stav nového roku.
Sestava si skutečné období k datu dohledá sama a spočítá se k němu správně;
pokud se liší od období vybraného v rozbalovacím seznamu, obrazovka to
zvýrazní hláškou s možností na dohledané období rovnou přepnout. Pro datum
bez založeného účetního období se zůstatky počítají kumulativně od počátku
historie. Filtr **Účet** nabízí:

| Volba | Otevřené položky |
|---|---|
| Vše | 311, 321, 314, 324, 315 a 325; prázdné účty se vynechají |
| 311 | vydané faktury a dobropisy odběratelů |
| 321 | přijaté faktury a dobropisy dodavatelů |
| 314 | poskytnuté a dosud nezúčtované zálohy |
| 324 | přijaté a dosud nezúčtované zálohy |
| 315 | ostatní pohledávky |
| 325 | ostatní závazky |

Server podporuje i jiný existující číselný účet a volitelné omezení na
partnera, běžná obrazovka však nabízí uvedených šest účtů. Explicitně zvolený
účet se zobrazí i s nulami.

Sestava zvládne 25 000 otevřených položek napříč zvolenými účty. Nad tímto
počtem ji server odmítne sestavit a vyzve k zúžení výběru - jedním účtem,
jedním partnerem nebo dřívějším rozvahovým dnem. Zkrácený seznam se záměrně
nevrací: součet otevřených položek by pak neodpovídal zůstatku hlavní knihy a
chybějící řádky by se vykázaly jako inventarizační rozdíl.

### 60.6.2 Dvě nezávislé strany konfrontace

**Zůstatek hlavní knihy** vychází ze všech zaúčtovaných řádků účtu do
rozvahového dne. Používá stejnou otevírací kotvu jako rozvaha a vylučuje
vlastní závěrkový převod období, aby uzavření knih historický stav nevynulovalo.
Zůstatek se otočí na normální stranu účtu: pohledávka na MD i závazek na Dal
se proto zobrazují kladně.

**Otevřené položky** vznikají z dokladů navázaných na účetní zápis. U každého
dokladu se vezme jeho zaúčtovaná hodnota v Kč a poměr úhrady známý k
rozvahovému dni:

`zbývá = zaúčtováno × (1 - poměr úhrady)`

Výpočet úhrady respektuje datum platby. Pozdější úhrada nezmění historické
saldokonto. Plně vyrovnaná položka se vynechá, záporná otevřená položka
(například dobropis) se zachová a snižuje součet partnera. Částky v cizí měně
se konfrontují v zaúčtované Kč hodnotě; obrazovka současně ukáže původní měnu.

Ostatní pohledávky a závazky se do sestavy dostanou až po zaúčtování. Sestava
bere jejich skutečný zápis na účtu a při částečné úhradě odečte jen platby,
které jsou k rozvahovému dni zaúčtované na stejný saldokontní účet. Úhradu,
kterou převod z jiného programu převzal jako uhrazenou mimo banku a pokladnu
(například zápočtem nebo interním dokladem), odečte stejně jako detail položky,
a to k jakémukoli dni, protože datum nenese. Storno a
přeúčtování se projeví od dne příslušného účetního zápisu. Koncepty a
potvrzené položky v daňové evidenci sem nepatří.

### 60.6.3 Zálohy 314 a 324

U záloh se neporovnává jen stav dokladu. Systém sleduje skutečnou
zaúčtovanou platbu a její následné čerpání:

- na **314** je otevřená poskytnutá záloha snížena o zúčtování ve finální
  přijaté faktuře i o kredit 314 z přijatého daňového dokladu k platbě. Platí to
  také pro samostatný daňový doklad k platbě použitý jako záloha při nákupu bez
  samostatné zálohové faktury,
- na **324** je přijatá platba vydané proformy snížena o daňový doklad k
  přijaté platbě a o vyúčtovací fakturu,
- na obou účtech snižuje zálohu i čerpání konečnou fakturou zadané v saldokontu
  nebo převzaté z převodu (jedna faktura může čerpat víc záloh a jedna záloha
  víc faktur), a to ode dne zaúčtování faktury.

Plně zúčtovaná záloha proto zmizí z otevřených položek, její historické
účetní pohyby však zůstanou v deníku.

### 60.6.4 Rozdíl a jeho interpretace

Pro každý účet platí:

`Rozdíl = zůstatek hlavní knihy - Σ otevřených položek`

Rovnost se kontroluje na haléře. Nulový rozdíl znamená, že dokladový rozpad
vysvětluje zůstatek účtu. Nenulový rozdíl může ukazovat na ruční zápis bez
vazby na doklad, chybějící či špatně datovanou úhradu, storno, kurzový pohyb
nebo nezúčtovanou zálohu.

Nulový rozdíl sám nepotvrzuje existenci pohledávky, vymahatelnost ani úplnost
závazků. Tyto skutečnosti je nutné doložit nezávislými podklady.

### 60.6.5 Dva pohledy: podle partnera / podle dokladů

Přepínač nad sestavou volí zobrazení:

- **Podle dokladů** (výchozí) - plochý seznam všech otevřených položek napříč
  účty a partnery v jedné tabulce: účet, partner, doklad, datum vystavení,
  splatnost, dní po splatnosti, částka, uhrazeno, zbývá. Sloupce jdou řadit
  kliknutím na záhlaví (výchozí řazení dle splatnosti), sestavu lze zúžit
  filtrem na partnera a na minimální počet dní po splatnosti.
- **Podle partnera** - partneři řazeni podle názvu, rozbalení partnera
  zobrazí jeho doklady se stejnými sloupci.

V obou pohledech jsou položky rozdělené do bloků **Pohledávky** (co dluží nám: vydané faktury, ostatní pohledávky a poskytnuté zálohy) a **Závazky** (co dlužíme my: přijaté faktury, ostatní závazky a přijaté zálohy), každý s celkovým součtem. Doklad vede na detail vydané nebo přijaté faktury.

Storno datované až po rozvahovém dni položku k dřívějšímu dni neskrývá.
K datu storna a později se účetní i dokladová strana vyruší.

### 60.6.6 Export

PDF vytvoří inventarizační protokol pro aktuální účet, partnera a rozvahový
den vždy v podobě podle partnera (zůstatek hlavní knihy, součet otevřených
položek, rozdíl a rozpad partnerů s doklady). XLSX export respektuje aktuálně
zvolený pohled - podle partnera stejně jako protokol, podle dokladů jako
plochý seznam pro další zpracování (např. filtrování v Excelu).

PDF umí navíc **členění podle doby po splatnosti** (podklad pro banky). Na
začátku protokolu je pak za každý účet tabulka partnerů se zbývajícími
částkami v pásmech **Do splatnosti**, **1–30 dní**, **31–60 dní**,
**61–120 dní** a **Nad 120 dní**, se součtem za účet. Pásma určují zadané
hranice: lze jich zadat jednu až šest (1 až 3650 dní), aplikace je seřadí sama.
Počet dní po splatnosti se počítá k rozvahovému dni sestavy, ne k dnešku, a
součet pásem odpovídá součtu otevřených položek účtu. Záloha čekající na
vyúčtování patří do pásma **Do splatnosti**. Pod členěním následuje běžný
rozpis dokladů, pokud ho nevypnete volbou **Včetně rozpisu dokladů**. Volba
se ukládá ke každému uživateli.

> [!TIP]
> Nenulový rozdíl řešte od konfrontačního pruhu přes partnera a doklad; pro
> ruční či kurzový zápis pokračujte proklikem do opisu účtu v
> [Hlavní knize](55_Hlavni_kniha.md).

## 60.7 Související kapitoly

- [Hlavní kniha](55_Hlavni_kniha.md) - opis účtu a párování otevřených položek
- [Inventarizace účtů](69_Inventarizace_rozvahovych_uctu.md) - inventarizační protokol
- [Úplnost dokladů](61_Uplnost_dokladu.md) - doklady po splatnosti a bankovní pohyby bez dokladu
- [Průvodce účetního](50_Pruvodce_ucetniho.md) - měsíční a roční postup
