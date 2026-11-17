# 120. Členové a členské příspěvky

V podvojném účetnictví podle vyhlášky 504 otevřete **Účetnictví → Členské příspěvky spolku**. Evidence spojuje člena spolku s kontaktem, právním titulem členství a účetně doloženými předpisy příspěvků.

Postup je vždy stejný: založte člena, zapište mu první revizi členství a teprve potom předpis příspěvku. Dokud není evidován žádný člen, stránka tyto tři kroky vypíše.

## 120.1 Založení a historie člena

Vyberte kontakt a vyplňte jedinečné členské číslo. Kontakt i číslo smí v organizaci patřit jen jednomu členovi. Založení člena samo nevytvoří pohledávku ani účetní zápis. V detailu přidejte první revizi členství s datem účinnosti, odkazem na stanovy nebo rozhodnutí a doložením členského vztahu bez vzájemného protiplnění. První revize musí mít stav **Aktivní**.

Změny stavu se zapisují jako další revize přes **Změnit stav členství** v detailu člena. Nová revize musí začínat po datu účinnosti poslední platné revize. Původní historie zůstává zachovaná. Budoucí pozastavení nebo ukončení nemění dnešní stav ani starší předpisy. Detail odlišuje dnešní stav od budoucích změn.

### Hromadná změna stavu a noví členové ze souboru

Změnu stavu u více členů najednou a zápis nových členů uděláte přes soubor. **Export XLSX** nebo **Export CSV** nad seznamem členů stáhne seznam podle použitých filtrů (rok, označení příspěvku, stav úhrady, hledání). Soubor obsahuje členské číslo, ID kontaktu, jméno nebo název, dnešní stav a datum od, počet předpisů, předepsanou, uhrazenou a zbývající částku, stav úhrady a poslední předpis za zvolený rok a označení příspěvku. Adresy ani kontaktní údaje v něm nejsou. Export smí každý, kdo vidí seznam členů. XLSX (nejvýše 5000 členů, větší seznam stáhněte jako CSV) má na druhém listu **Návod** s popisem sloupců. Upravujte raději XLSX: v CSV Excel ořízne úvodní nuly čísel (0042 → 42) a takové číslo už neodpovídá členovi. Text začínající znakem =, +, - nebo @ (případně tabulátorem nebo koncem řádku) má v CSV na začátku apostrof, aby ho Excel nebral jako vzorec. Text, který sám začíná apostrofem, má v obou formátech apostrof zdvojený (členské číslo '-1 je v souboru ''-1). Import tyto apostrofy zase odebere, takže je v souboru neopravujte. CSV uložené českým Excelem (kódování Windows-1250) import přečte také; soubor se smíšeným kódováním (část UTF-8, část Windows-1250) odmítne, aby se do adresáře nezapsala zkomolená jména.

Prázdné sloupce **Nový stav**, **Platí od**, **Podklad** a **Doložení** vyplňte jen u členů, kterých se změna týká. Nový stav zapište jako `active`, `suspended` nebo `ended` (nebo česky aktivní, pozastavené, ukončené), datum jako RRRR-MM-DD nebo D. M. RRRR. Podklad je odkaz na doklad (usnesení, přihláška), doložení je volitelné a prázdné převezme text podkladu. Nové členy připište jako další řádky s novým členským číslem, stavem `active`, datem a podkladem. Nový člen se napojí na kontakt podle **ID kontaktu**, jinak podle **IČO** aktivního (nearchivovaného) kontaktu. Když kontakt s tím IČO není nebo není vyplněné ani ID, ani IČO, založí se nový kontakt ze sloupce **Jméno / název**. Zakládat kontakty smí import jen tomu, kdo má právo vytvářet kontakty v adresáři; jinak u takového řádku uveďte ID nebo IČO existujícího kontaktu. IČO má nejvýše 8 číslic (mezery mezi skupinami nevadí), úvodní nuly ztracené v Excelu se doplní. Náhled u existujícího kontaktu ukáže jeho jméno a u zakládaného kontaktu výsledné IČO, takže záměnu poznáte před potvrzením. Nový kontakt se s IČO, které nemá platný kontrolní součet, nezaloží; opravte IČO, nebo sloupec nechte prázdný. Má-li IČO víc kontaktů, uveďte ID kontaktu. Tentýž kontakt ani totéž IČO nesmí v souboru patřit dvěma novým členům. Každý člen smí být v souboru jen na jednom řádku; dvě po sobě jdoucí změny (pozastavení a pozdější obnovení) zapište dvěma importy nebo druhou v detailu člena.

**Import ze souboru** (jen s právem zápisu členské evidence, soubor do 2 MB a nejvýše 2000 řádků; u většího seznamu smažte řádky beze změny, stačí řádky s novým stavem a noví členové) nejdřív ukáže náhled po řádcích: nová revize, nový člen, beze změny, nebo chyba s důvodem. Existující člen se páruje jen podle členského čísla, velikost písmen ani diakritika nerozhoduje (SYN-Č1 je totéž číslo jako syn-c1). Podle jména se nepáruje nikdy, jméno slouží jen k založení nového kontaktu. ID kontaktu nebo IČO u existujícího člena musí odpovídat jeho kontaktu; kontakt opravíte v detailu člena. Řádek, jehož nový stav už člen k zadanému dni má, je beze změny. Datum musí být po datu poslední platné revize, i budoucí: má-li člen naplánované ukončení od 1. 1., ukončení od 1. 12. import odmítne a nejdřív zrušte plánovanou revizi v detailu člena. Obsahuje-li soubor chybný řádek, import nejde potvrdit: opravte soubor a nahrajte ho znovu. Potvrzení zapíše řádky po jednom stejnou cestou jako ruční založení člena a revize. Změní-li mezitím někdo členy, potvrzení se odmítne a zobrazí se nový náhled. Když se člen změní až během zápisu, jeho řádek se odmítne a výsledek to u řádku uvede; ostatní řádky se zapíšou. Skončí-li potvrzení chybou spojení nebo vypršením času, nahrajte stejný soubor znovu: náhled ukáže, které řádky už jsou zapsané, a potvrzení dopíše zbytek. Opakovaný import stejného souboru nic nezdvojí, už zapsané řádky jsou beze změny. Opravíte-li po importu členské číslo v detailu člena, stáhněte nový export: ve starém souboru původní číslo člena nenajde a řádek s jeho ID kontaktu skončí chybou, že kontakt už je členem. Import nikoho automaticky neukončuje a žádné datum ukončení nedoplňuje.

### Oprava chyb v evidenci

Dokud na záznamu nestojí předpis příspěvku, jdou chyby opravit. Akce opravy najdete v detailu člena v nabídce **…**. Když oprava právě možná není, je akce zašedlá a uvádí důvod. Zašedlá je i tehdy, když máte otevřený formulář změny stavu, předpisu nebo storna nebo rozepsanou první revizi člena, aby se rozepsané údaje neztratily; formulář nejdřív dokončete, zavřete nebo vyprázdněte. Změní-li mezitím údaje člena někdo jiný, potvrzení opravy se odmítne, oprava se zavře a detail se načte znovu; opravu otevřete nad aktuálními údaji. Dokud máte rozepsanou revizi (první i změnu stavu), detail se neobnovuje ani při návratu do záložky a **Obnovit** je zašedlé. Stejně tak **Změnit stav** počká, dokud je otevřená oprava. Každá oprava se zapíše do auditní stopy.

- **Opravit kontakt a číslo** změní přiřazený kontakt nebo členské číslo, dokud člen nemá žádný předpis, ani stornovaný. Kontakt i číslo nesmí patřit jinému členovi. Oprava jen velikosti písmen v čísle je možná. Prohození čísel dvou členů udělejte přes dočasné číslo.
- **Zrušit poslední revizi** (v nabídce **…**) zruší poslední platnou revizi, pokud na ní nestojí předpis, ani stornovaný. Revize stojí pod předpisem, když platí k jeho účetnímu datu nebo zasahuje do jeho období. Zadejte důvod. Zrušená revize zůstane v historii přeškrtnutá a zrušení se zapíše jako další revize s důvodem. Stav členství, nový předpis, hromadný předpis i přehled se pak řídí předchozí platnou revizí. Opravenou revizi můžete zapsat i ke stejnému dni. Postupně jde zrušit i starší revize. Člen, kterému zrušíte všechny revize, nemá žádné členství: hromadný předpis pro všechny aktivní ho vynechá, smazat ho kvůli záznamu o zrušení nejde. V seznamu členů u něj místo stavu stojí, že jsou všechny revize zrušené. Rozpracovaný hromadný předpis opravu ani zrušení nebrání; položka dotčeného člena se při potvrzení dávky odmítne, protože se změnily její podklady, a člena zahrnete do nové dávky.
- **Smazat člena** (v nabídce **…**) jde jen u člena bez jakékoli revize, i zrušené, a bez předpisu. Člen v rozpracovaném hromadném předpisu nejde smazat, dokud dávku nedokončíte nebo nezrušíte. Z dokončené nebo zrušené dávky se odstraní jeho odmítnutá položka a souhrn dávky se o ni sníží. Kontakt v adresáři zůstává a po smazání člena ho jde smazat. Detail starší dávky ukazuje aktuální kontakt a číslo člena.

Revize, na které stojí předpis, ani člena s předpisem opravit nejde. Před založením člena i před potvrzením revize proto údaje zkontrolujte.

## 120.2 Předpis příspěvku

### Sazby členských příspěvků

V rozbalovací části **Sazby členských příspěvků** založte šablony své organizace. Každá sazba obsahuje název, částku na jednoho člena, účet pohledávky, výnosový účet povolený nastavenou rolí přijatých členských příspěvků, označení příspěvku, výchozí délku období a texty podkladu. Analytiky lze členit podle vlastních potřeb; nemusí odpovídat částce příspěvku. Vstupní poplatek může mít vlastní sazbu a vlastní označení. Rodinná částka za domácnost se touto sazbou nerozděluje.

U nového předpisu vyberte sazbu a zvolte **Použít sazbu**. Výchozí délka (měsíc, čtvrtletí, pololetí, rok) se počítá od vyplněného začátku příspěvku, jinak od účetního data. Kalendářní sazba vyžaduje začátek prvním dnem měsíce. Konec připadne na poslední den posledního měsíce zvolené délky. Volba ručního období data nemění. Před náhledem vždy zkontrolujte a případně upravte obě data, částku, účty a texty. Délka příspěvku neurčuje platnost členství ani automaticky nezapisuje jeho prodloužení či ukončení. Předpis lze zadat také bez sazby.

Sazba smí předvyplnit všechny tři účetní dimenze, pokud je v ní firma výslovně nastavila. Ve formuláři zůstávají viditelné a upravitelné a náhled je ověří stejně jako ruční volbu. Z výnosového účtu se dimenze automaticky neodvozují.

Změna sazby nepřepisuje rozepsaný ani zaúčtovaný předpis. Pro změnu rozepsaného předpisu sazbu znovu výslovně použijte. Dřívější předpisy drží skutečně potvrzené částky a účetní podklady. Nepoužívanou sazbu vyřaďte vypnutím **Nabízet pro nové předpisy**; zůstane dohledatelná přes **Zobrazit i vyřazené sazby**. Při současné změně stejné sazby z jiného okna použijte v editoru **Načíst aktuální sazbu**. Před přepsáním neuložených změn dostanete upozornění; obnovení samotného katalogu editor nepřepíše.

### Zadání a potvrzení

Zvolte **Nový předpis příspěvku**. Pokud firmě nebo zvolenému období chybí některý předpoklad (otevřené účetní období, potvrzený právní profil spolku, osy činnosti, druhu činnosti a daňového režimu v dimenzích, povolený výnosový účet v účtové osnově), formulář ho hned nahoře vypíše s odkazem, kde ho doplnit.

Vyberte otevřené účetní období, účetní datum, splatnost a období, za které příspěvek náleží. Vyplňte označení příspěvku (například `CP-2027`), částku, pohledávkový a výnosový účet z rozvrhu, odkaz na titul a doložení částky. Jakmile začnete formulář vyplňovat, vypíše pod sebou všechno, co ještě chybí.

Variabilní symbol u jednotlivého předpisu můžete před náhledem zadat. Když pole necháte prázdné, použije se symbol dřívějšího předpisu stejného titulu a období (například po stornu), jinak číselné členské číslo do deseti číslic a pro jiné členské číslo se vytvoří číselný symbol. Symbol ze samých nul zadat nejde. V náhledu i detailu předpisu ho zkontrolujte. Hromadný předpis určí symbol každému členovi samostatně. Stejný symbol může mít více předpisů, například v různých letech, proto sám neurčuje, který příspěvek platba hradí.

Stejné označení nejde u člena předepsat dvakrát na překrývající se období. Po stornu předpisu lze stejné označení a období předepsat znovu. Členství musí být aktivní k účetnímu datu a po celé období příspěvku, od prvního do posledního dne. Pokud není, formulář na to upozorní ještě před náhledem a náhled skončí chybou.

Výnos se účtuje na účet povolený rolí přijatých členských příspěvků; ve výchozím nastavení jde o účet 684 nebo jeho analytiku. Když období příspěvku přesahuje rozvahový den účetního období, náhled to oznámí a krok uzávěrky **Časové rozlišení** odloží část připadající na další období na účet 384 a v dalším období ji rozpustí.

Vyberte nebo zkontrolujte činnost, druh činnosti a daňový režim z účetních dimenzí firmy. Sazby se spravují pouze v samostatném číselníku na stránce členů s oprávněním ke správě účetních šablon. Použití sazby vyžaduje oprávnění k zaúčtování. Sazba bez dimenzí ponechá ruční výběr; přepis odlišných dimenzí vyžaduje potvrzení. Předvyplnění je možné jen výslovně nastavenou sazbou, nikoli podle výnosového účtu. V náhledu zkontrolujte také ověřené členění; bez jeho shody s vaší volbou nelze předpis potvrdit.

Účetní období musí mít potvrzený právní profil spolku s hlavní nepodnikatelskou činností. Zkontrolujte také revizi členství platnou k účetnímu datu. Samotné číslo účtu ani označení organizace neprokazuje právní titul.

Zvolte **Zobrazit náhled**, zkontrolujte člena, období, částku a účty a použijte **Potvrdit a zapsat**. Potvrzení vytvoří pohledávku a navazující účetní doklad v jednom kroku. Změna formuláře vyžaduje nový náhled. Zápis vyžaduje oprávnění k zaúčtování a přihlášenou relaci, případně
API token s [Plným zápisem](103_API.md#103973-plny-zapis-tokenem-systemwrite).

Členský předpis není automatickým rozhodnutím o osvobození příjmu. Daňový režim doložte v daňovém posouzení příslušného období.

### Hromadný předpis podle sazby

Správce instalace může proměnnou prostředí `MYINVOICE_MEMBERSHIP_BATCH_MAX_MEMBERS` nastavit strop velikosti dávky na 1–5000 členů; výchozí hodnota je 5000.

V seznamu zaškrtněte členy a otevřete **Hromadný předpis příspěvků**. Výběr se zachovává i při načítání dalších členů, nejvýše 500. Místo zaškrtnutých můžete výslovně zvolit **Všichni aktivní k účetnímu datu**; tento výběr nemá omezení na právě zobrazenou stránku. Členové s budoucím ukončením se posuzují podle účetního data. Každý předpis přesto vyžaduje aktivní členství po celé období příspěvku.

Pro dávku vyberte sazbu s kalendářní délkou období a zkontrolujte společné údaje ve stejném formuláři jako u jednotlivého předpisu. U dávky určuje sazba částku, účty a délku období; tyto údaje nelze přepsat. Začátek kalendářního období musí být první den měsíce. Upravit můžete data, podklady a dimenze. **Připravit náhled dávky** uloží její společné údaje a výběr členů. Založení ani příprava náhledu nic neúčtuje. Příprava postupuje po omezených částech; k potvrzení musí nejdřív dokončit náhled všech členů.

Souhrn zahrnuje celou dávku, ne jen zobrazenou stránku: počet členů, počet připravených předpisů, částku a rozpad zápisu na pohledávkový a výnosový účet. U členů uvidíte **Připraveno k zápisu**, **Předpis již existuje** nebo **Vynecháno s důvodem**. Existující předpis stejného označení a přesného období se nepřepisuje ani nevytváří podruhé; jeho částka se může od vybrané sazby lišit. Jiný překryv období se odmítne s důvodem. Výsledky členů lze stránkovat po 50.

Při přesahu rozvahového dne náhled ukáže souhrnnou částku časového rozlišení připravených předpisů a vysvětlí odložení na účet 384 v kroku uzávěrky. Odmítnutí členové a dříve existující předpisy se do této částky nezapočítávají.

Zkontrolujte společné období, data, částku na člena, účty, dimenze a podklady. Potvrzovací souhlas se vztahuje na všechny připravené členy dávky, nejen na viditelnou stránku. Až potom zvolte **Potvrdit dávku a zapsat předpisy**. Každý člen se účtuje samostatnou operací se stejnými kontrolami jako ruční předpis. Změna podkladů nebo uzavření období po náhledu může konkrétní předpis odmítnout; ostatní výsledky zůstávají uložené.

Přípravu nebo zápis můžete pozastavit po současné části. Přes **Uložené dávky a pokračování** se lze vrátit i po obnovení stránky nebo přerušení spojení. Při pokračování se dokončí nezpracovaní členové a předchozí finanční zápisy se nezdvojí, ani když se předchozí odpověď ztratila. Po hromadném zápisu se obnoví otevřený detail člena; případné rozpracované potvrzení jednotlivého předpisu se nejdřív nechá bezpečně dokončit.

Dávku lze také výslovně zrušit. Zrušení zastaví další zpracování a zachová již zapsané předpisy. Po vypršení souhlasu připravte nový náhled s novým souhlasem.

Pro změnu společných údajů nebo nové zpracování členů po opravě odmítnutých podkladů použijte **Nová dávka**. Formulář převezme uložená data, texty, dimenze a původně zaškrtnuté členy; částku, účty a délku období znovu načte z aktuální sazby, také po otevření dávky z historie. U volby všech aktivních se jejich výběr znovu vyhodnotí k novému účetnímu datu. Pro jiný zaškrtnutý výběr dávku zavřete a vyberte členy v seznamu. Tato výslovná akce vytvoří nový požadavek a nový náhled; pouhé opakování neúspěšného spojení pokračuje v původním. Stav členství se dávkou nemění. Rozpracované dávky se do anonymizované kopie nepřenášejí; účetní operace se v ní zachovávají a anonymizují samostatně.

## 120.3 Úhrady a kontrola zůstatku

### Přehled příspěvků v seznamu členů

Seznam můžete prohledat podle jména a příjmení, názvu kontaktu nebo členského čísla. Zadáte-li víc slov (například celé jméno), musí se najít každé z nich, na pořadí nezáleží. Zvolte rok příspěvku a případně přesné označení ve výběru titulů. Vyhledávání a rok se použijí po krátké prodlevě, takže rozepsaná hodnota seznam nepřenačte. Titul a stav úhrady se použijí ihned; pokud ale právě běží prodleva rozepsaného hledání nebo roku, prodleva se spustí znovu a po jejím uplynutí se použijí všechny změny společně. Aktivní filtry jsou vidět jako odstranitelné štítky a ukládají se do adresy stránky. Rok vybírá příspěvky, jejichž období do zvoleného roku zasahuje. Pro zobrazení všech období jednoho titulu vyberte označení, rok nechte prázdný a stav úhrady ponechte **Vše**. Filtrování podle stavu úhrady vyžaduje konkrétní rok; při prázdném roce se doplní aktuální rok. Přehled všech období může nad velkou historií trvat déle. Volba roku nemění účetní datum ani stav členství.

U člena uvidíte částku předepsaných aktivních příspěvků za vybraný titul nebo rok a stav **Uhrazeno**, **Neuhrazeno**, **K prověření** nebo **Bez předpisu pro zvolený titul nebo rok**. Stornované předpisy se do těchto součtů nezahrnují. Úhrada a zůstatek vycházejí ze stejného nativního párování jako detail člena.

Předpis bez spárované úhrady se zobrazí jako neuhrazený s nulovou úhradou a plným zůstatkem. Pokud jsou účetní důkazy poškozené nebo je párování nejednoznačné, úhrada a zůstatek se zobrazí jako neurčené, nikoli jako potvrzený dluh. Filtr **Neuhrazeno** proto zahrnuje předpisy bez spárované úhrady i předpisy uhrazené jen zčásti; předpisy k prověření mají vlastní filtr **K prověření**. Filtry se uplatní před stránkováním celého seznamu.

Změna filtrů zruší zaškrtnuté členy pro novou dávku a oznámí to. Obnova dat ověří celý výběr včetně členů z dalších stránek a zachová ty, kteří stále odpovídají filtrům. Pouhé použití nezměněných filtrů výběr zachová. Již uložená dávka se tím nemění. Během zpracování dávky se změněné filtry ani odebrané štítky nepoužijí hned, ale až po jeho skončení; přejdete-li mezitím na jiné filtry odkazem nebo zpět v historii prohlížeče, použijí se po skončení filtry z adresy; **Načíst znovu** mezitím obnoví seznam podle dosud použitých filtrů. Detail otevřeného člena stále ukazuje celou jeho historii a předpisy, nezávisle na filtrech seznamu.

### Párování a kontrola období

Pohledávky se zobrazují po stránkách. Přehled rozlišuje předepsanou částku a zůstatek k úhradě. Úhrada je doložená teprve přes skutečné párování s nativním bankovním nebo pokladním dokladem; předpis bez spárované úhrady má zůstatek ve výši celé částky.

U příchozí korunové platby v bance otevřete **Spárovat**. Sekce **Členské příspěvky** nabídne nejdřív předpisy se shodným variabilním symbolem a potom nespárované předpisy se shodnou částkou; u každého je štítek důvodu shody. Předpis bez variabilního symbolu se podle částky nabídne, jen dokud nemá žádnou úhradu; jinak ho dohledejte vyhledáním. Vyhledávání umožňuje dohledat konkrétního člena podle čísla, názvu, jména či příjmení. Zadáte-li víc slov (například celé jméno), musí se najít každé z nich, na pořadí nezáleží. Předpisy jednoho příspěvku najdete také zadáním jeho označení (například `CP-2027`); označení se hledá jako celý zadaný text a rozlišuje velká a malá písmena. Variabilní symbol se ve vyhledávání nehledá; předpis s jiným variabilním symbolem, než uvedl plátce, dohledejte podle člena. Další výsledky načtete přes **Další předpisy**. Plátce nemusí být člen, například když platí rodič nebo organizace. Při více odpovídajících předpisech není žádný vybraný automaticky.

Zaškrtněte konkrétní předpisy a zkontrolujte členy, období a součet před potvrzením **Spárovat vybrané příspěvky**. Jeden předpis může být uhrazen částečně. Pro několik předpisů musí platba přesně odpovídat jejich otevřeným částkám a předpisy musí mít stejný účet pohledávky i stejné dimenze. Bankovní pohyb se nerozděluje. Již částečně hrazené předpisy párujte samostatně. Přeplatek zde nelze přiřadit.

Úhrada převezme dimenze předpisu, takže pohledávka je vyrovnaná i ve výkazech podle dimenzí. Typy dimenzí, které předpis určuje, dostane stejně jako u úhrady faktury celý bankovní zápis, včetně řádku banky. Dimenze, které pohyb dostal při zaúčtování automaticky (výchozí hodnoty firmy či bankovního účtu, pravidlo zaúčtování bankovního pohybu, pravidlo dimenzí pro účet pohledávky), se z řádku pohledávky po spárování odeberou, pokud je předpis neurčuje; na řádku banky zůstanou. Předpis se vystavuje bez pravidel dimenzí pro účet pohledávky. Párování zaúčtuje nezaúčtovaný pohyb rovnou s dimenzemi předpisu, takže povinné pravidlo pro typ dimenze, který předpis nese, projde. Povinné pravidlo bez výchozí hodnoty pro jiný typ dimenze zaúčtování odmítne; doplňte pravidlu výchozí hodnotu, z řádku úhrady se po spárování odebere. Má-li řádek pohledávky dimenze odlišné od předpisu zadané ručně nebo ze staršího zaúčtování, párování se odmítne; upravte dimenze pohybu, nebo ho odúčtujte a spárujte znovu, případně spárujte jiný předpis.

Je-li pohyb zaúčtovaný na jiný účet než účet pohledávky předpisu (například pravidlem zaúčtování), nebo by dimenze předpisu přesunuly jiný řádek jeho zaúčtování na jiný analytický účet, pohyb odúčtujte a spárujte znovu. Párování ho pak zaúčtuje samo jediným řádkem úhrady proti účtu pohledávky.

Spárovaný bankovní pohyb je zamčený: nelze změnit jeho zaúčtování ani dimenze, odúčtovat ho ani ho přiřadit jiné položce. Párování zrušíte v opisu účtu pohledávky v přehledu **Otevřené položky - párování** ([kap. 55.8.5.1](55_Hlavni_kniha.md)); potom je pohyb opět volný. Zamčená je i platba, kterou jste s předpisem spárovali ručně v saldokontu, také u stornovaného předpisu, s nímž byla platba ručně spárována.

Párovat lze také ručně, doporučujeme ale párování v bance: ruční párování v saldokontu (ani použití návrhů párování) dimenze úhrady na předpis nepřevede a pohledávka pak může zůstat po dimenzi nevyrovnaná. Otevřete opis účtu pohledávky v hlavní knize, přepněte na **Otevřené položky - párování** a spojte řádky předpisů s řádkem platby do okruhu ([kap. 55.8.5.1](55_Hlavni_kniha.md)). Vyrovnaný okruh s více platnými členskými pohledávkami prokazuje úhradu všech. Částečně uhrazený společný okruh zůstává nejednoznačný, protože nelze určit uhrazené členy. Zrušte jeho párování a platbu spárujte znovu: s předpisy, jejichž součet jí přesně odpovídá, nebo s jedním předpisem jako částečnou úhradu.

U poškozených důkazů nebo nejednoznačného párování se částka nevydává za potvrzený dluh. Opravte párování v opisu účtu pohledávky.

Kontrola vybraného účetního období pod seznamem členů porovnává členské pohledávky, potvrzené operace a pohledávkové i výnosové řádky účetního deníku. Po zápisu předpisu, storna nebo revize se dřívější výsledek kontroly skryje; spusťte ji znovu. Případný rozdíl vyžaduje kontrolu původu dokladů.

## 120.4 Storno a opakování požadavku

Storno zahajte z konkrétní pohledávky. Zvolte účetní datum v otevřeném období a doložte důvod. Náhled vychází z původní potvrzené operace. Po výslovném potvrzení vznikne navazující storno; původní pohledávka i její historie zůstávají dohledatelné.

Předpis spárovaný v saldokontu (obvykle s úhradou) stornovat nejde, stejně jako ostatní pohledávky. Nejprve zrušte jeho párování. U společné platby více členů se tím uvolní i předpisy ostatních členů; ty pak spárujte znovu.

Při nejistém výsledku odeslání obnovte přehled nebo opakujte stejný zmrazený požadavek. Stejný požadavek se stejným identifikátorem nevytvoří druhý předpis. Před založením nového požadavku ověřte, zda se původní operace již nezapsala.

## 120.5 Čtení přes veřejné API a MCP

Kurátorované katalogy členských rolí a pohledávek jsou dostupné s oprávněním ke čtení účetnictví také přes API token a MCP. Seznamy i detaily používají stejnou obálku; stránka obsahuje nejvýše 100 záznamů a pokračovací kurzor je vázaný na firmu a filtry. Výstup neobsahuje jména, členská čísla, právní podklady ani interní otisky a aktéry. U člena s vymazanými osobními údaji (oddíl 120.6) je `erased` true a `client_id` prázdné.

U členských rolí zadejte rozhodné datum `as_of` a případně okamžik tehdejší znalosti `known_at`. Pokud jej vynecháte, připne jej první stránka. Později zaznamenaná revize ani její zrušení tak nezmění navazující stránky téhož čtení. Oprava kontaktu člena bez předpisu se projeví i pro dřívější okamžik znalosti. Předchozí revize role ukazuje na předchozí platnou revizi, zrušené revize přeskakuje. U pohledávek se čte aktuální účetní stav a doložená nativní bankovní nebo pokladní párování; historický okamžik úhrad se nepřijímá. U pohledávek k prověření zůstávají uhrazená i otevřená částka `null`. Katalog sám nepotvrzuje osvobození příjmu ani oprávnění k finanční operaci.

Historické role vyžadují průkazný okamžik zaznamenání. Pokud jej starší záznam nemá doložený, časový katalog vrátí chybu místo odhadování časové zóny nebo vynechání člena. U pohledávky může být čas zaznamenání `null` se stavem `unproven`; ověření její částky a účetních podkladů tím zůstává samostatné. Původní časové údaje se při aktualizaci ani anonymizované kopii nepřepočítávají odhadem.

## 120.6 Výmaz osobních údajů bývalých členů

Osobní údaje bývalého člena se po uplynutí lhůt vymažou v sekci **Výmaz osobních údajů bývalých členů** pod sazbami. Sekce je vidět s právem **Schválit a provést výmaz osobních údajů bývalých členů**. Ve výchozím stavu ho má správce; jiné roli ho přidělí správce v editoru rolí. Nic se nemaže automaticky. Sestaví se návrh, člověk ho schválí a teprve pak se provede.

**Kdy jde údaje vymazat.** Kandidátem je člen, jehož poslední platná revize má stav **Ukončeno**. Lhůta běží od ukončení členství a od konce účetního období posledního účetního záznamu, který na členovi stojí: předpisu, storna nebo úhrady spárované s jeho předpisem. Platí ta lhůta, která skončí později:

- člen s účetním záznamem: 5 let od konce účetního období posledního záznamu (§ 31 odst. 2 písm. b zákona o účetnictví),
- člen bez účetního záznamu: 1 rok od ukončení členství.

Poslední den lhůty do ní patří; vymazat jde až den poté. Výmaz drží také neuhrazený nebo neprokázaný předpis, rozpracovaný hromadný předpis s členem a zadržení podle § 32 zákona o účetnictví na rok s jeho účetním záznamem (**Účetnictví → Retenční lhůty**). Zadržení celého účetnictví drží všechny členy. Sekce ukazuje, kolik členů lze k dnešku vymazat a co drží ostatní.

**Návrh a schválení.** **Sestavit návrh výmazu** zařadí všechny členy, kterým lhůta uplynula a nic je nedrží (nejvýše 500 v jednom návrhu). Otevřený může být jen jeden návrh. Před provedením návrh ukazuje číslo a jméno každého člena, aby šlo zkontrolovat, koho se týká. Návrh jde **Schválit** nebo **Zamítnout**. Schválí-li ho tentýž uživatel, který ho sestavil, jde provést až po 72 hodinách a do té doby jde schválení **Vzít zpět**. Schválí-li ho jiný uživatel, provést ho jde hned.

**Provedení.** Opište potvrzovací frázi a zvolte **Provést výmaz**. Každý člen se před výmazem posoudí znovu. Kdo se od schválení změnil (například znovu vstoupil, přibyl mu předpis nebo zadržení), se přeskočí s uvedeným důvodem a jeho údaje zůstanou. Stejně se přeskočí člen, u kterého výmaz narazí na technickou chybu. Jedno spuštění zpracuje nejvýše 50 členů; u většího návrhu pokračujte tlačítkem **Pokračovat ve výmazu**. Přerušený výmaz jde stejně dokončit. Schválený návrh, který dokončit nechcete, ukončete v nabídce **…** volbou **Ukončit návrh**; provedené položky zůstanou a ostatní členové půjdou do dalšího návrhu.

**Co se vymaže a co zůstane.** Účetní záznamy zůstávají beze změny: deník, předpisy, storna, částky, saldokonto i podklady pro daňové přiznání. U člena se vymaže:

- členské číslo (nahradí ho pseudonym `vymazano-…`),
- odkaz a doložení v revizích členství a důvod zrušení revize,
- kopie čísla a těchto textů v uložených podkladech předpisů, odkaz a doložení titulu předpisu a důvod storna v nich,
- důvod předpisu a variabilní symbol v uložených požadavcích a u předpisů,
- kontakt, pokud ho nepoužívá jiný doklad, modul ani k němu připojený dokument: zmizí jméno, adresa, e-mail, telefon, identifikátory, bankovní účty a e-mailové kontakty, kontakt se archivuje a z auditní stopy jeho založení a úprav zmizí název, IČO i e-mailové kontakty. Kontakt, na kterém stojí například faktura, zůstane beze změny, protože ho drží ten doklad,
- členské číslo, kontakt a důvod v auditní stopě oprav člena.

Důvod storna zapsaný jako popis účetního zápisu zůstává, protože je součástí účetního záznamu. Do důvodů storna proto nepište osobní údaje. Zůstávají také bankovní pohyby úhrad (jméno a účet plátce, variabilní symbol): jsou to účetní záznamy banky s vlastní lhůtou a přes ně jde vymazaného člena dohledat. Dokument připojený ke kontaktu (například sken přihlášky) výmaz nemění a kontakt kvůli němu zůstane; po kontrole ho smažte ručně.

Výmaz anonymizuje jen kontakt, který má člen přiřazený v den výmazu. Kontakt, který měl člen přiřazený dřív a opravil se (oddíl 120.1), ani kontakt smazaného člena se nemění; pokud ho nic nepoužívá, smažte ho v adresáři ručně.

Vymazaný člen zůstává v seznamu jako **Vymazaný člen č. …** bez jména, ani tehdy, když jeho kontakt drží jiný doklad. Podle jména ho najít nejde. V detailu je historie i předpisy, ale členství, předpisy, storno, opravu ani smazání už měnit nejde. Členské číslo ve tvaru `vymazano-…` je vyhrazené. Když vymazaný člen znovu vstoupí a jeho kontakt zůstal kvůli jinému dokladu, založte mu nový kontakt; původní kontakt zůstává svázaný s vymazaným členem. Zruší-li se po výmazu párování úhrady jeho předpisu, pohledávka se znovu otevře; spárujte ji s úhradou ručně (variabilní symbol už nemá).

**Průkaznost.** Revize a podklady předpisů jsou zřetězené otisky. Výmaz uložené otisky nemění, takže řetěz i podklady daňového přiznání na ně dál odkazují. Před výmazem se celý řetěz člena ověří. Přepisu brání databáze, dokud nejde o schválený návrh, a i pak pustí změnu jen osobních údajů. Přepsané záznamy dostanou pečeť svázanou s návrhem výmazu a otisky pečetí se zapíšou do zřetězené auditní stopy. Detail a historie člena, předpisy, veřejný katalog i podklady daňového přiznání porovnají každý zapečetěný záznam s pečetí i s tímto zápisem; pozdější změna se ohlásí jako neprůkazná historie. Změnu samotného zápisu v auditní stopě odhalí kontrola řetězu v Diagnostice, v promazané části auditní stopy jen v rozsahu, v jakém tam řetěz ještě ověřitelný je. Redigované záznamy auditní stopy se ověřují stejně: každá redakce je sama zápisem v řetězu.

**Doklad o výmazu.** Provedený návrh zůstává v přehledu návrhů jako doklad: kdo a kdy ho schválil a provedl, podle jaké lhůty a s jakým výsledkem u každé položky. Jméno, číslo ani kontakt v něm nejsou.

**Smazaný člen.** U člena smazaného v evidenci (oddíl 120.1) zůstává v auditní stopě jeho číslo a kontakt. Rok po smazání ho návrh výmazu zařadí jako auditní stopu smazaného člena a provedení číslo i kontakt v auditu nahradí. Zadržení celého účetnictví (bez roku) drží i tyto stopy.
