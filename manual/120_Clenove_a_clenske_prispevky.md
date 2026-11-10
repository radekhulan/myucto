# 120. Členové a členské příspěvky

V podvojném účetnictví podle vyhlášky 504 otevřete **Účetnictví → Členské příspěvky spolku**. Evidence spojuje člena spolku s kontaktem, právním titulem členství a účetně doloženými předpisy příspěvků.

Postup je vždy stejný: založte člena, zapište mu první revizi členství a teprve potom předpis příspěvku. Dokud není evidován žádný člen, stránka tyto tři kroky vypíše.

## 120.1 Založení a historie člena

Vyberte kontakt a vyplňte jedinečné členské číslo. Kontakt i číslo smí v organizaci patřit jen jednomu členovi. Založení člena samo nevytvoří pohledávku ani účetní zápis. V detailu přidejte první revizi členství s datem účinnosti, odkazem na stanovy nebo rozhodnutí a doložením členského vztahu bez vzájemného protiplnění. První revize musí mít stav **Aktivní**.

Změny stavu se zapisují jako další revize přes **Změnit stav členství** v detailu člena. Nová revize musí začínat po datu účinnosti poslední revize. Původní historie zůstává zachovaná. Budoucí pozastavení nebo ukončení nemění dnešní stav ani starší předpisy. Detail odlišuje dnešní stav od budoucích změn.

Člena nejde smazat, přiřazený kontakt ani členské číslo nejde změnit a zapsanou revizi nejde vzít zpět. Před založením člena i před potvrzením revize proto údaje zkontrolujte.

## 120.2 Předpis příspěvku

### Sazby členských příspěvků

V rozbalovací části **Sazby členských příspěvků** založte šablony své organizace. Každá sazba obsahuje název, částku na jednoho člena, účet pohledávky, výnosový účet povolený nastavenou rolí přijatých členských příspěvků, označení příspěvku, výchozí délku období a texty podkladu. Analytiky lze členit podle vlastních potřeb; nemusí odpovídat částce příspěvku. Vstupní poplatek může mít vlastní sazbu a vlastní označení. Rodinná částka za domácnost se touto sazbou nerozděluje.

U nového předpisu vyberte sazbu a zvolte **Použít sazbu**. Výchozí délka (měsíc, čtvrtletí, pololetí, rok) se počítá od vyplněného začátku příspěvku, jinak od účetního data. Kalendářní sazba vyžaduje začátek prvním dnem měsíce. Konec připadne na poslední den posledního měsíce zvolené délky. Volba ručního období data nemění. Před náhledem vždy zkontrolujte a případně upravte obě data, částku, účty a texty. Délka příspěvku neurčuje platnost členství ani automaticky nezapisuje jeho prodloužení či ukončení. Předpis lze zadat také bez sazby.

Sazba smí předvyplnit všechny tři účetní dimenze, pokud je v ní firma výslovně nastavila. Ve formuláři zůstávají viditelné a upravitelné a náhled je ověří stejně jako ruční volbu. Z výnosového účtu se dimenze automaticky neodvozují.

Změna sazby nepřepisuje rozepsaný ani zaúčtovaný předpis. Pro změnu rozepsaného předpisu sazbu znovu výslovně použijte. Dřívější předpisy drží skutečně potvrzené částky a účetní podklady. Nepoužívanou sazbu vyřaďte vypnutím **Nabízet pro nové předpisy**; zůstane dohledatelná přes **Zobrazit i vyřazené sazby**. Při současné změně stejné sazby z jiného okna použijte v editoru **Načíst aktuální sazbu**. Před přepsáním neuložených změn dostanete upozornění; obnovení samotného katalogu editor nepřepíše.

### Zadání a potvrzení

Zvolte **Nový předpis příspěvku**. Pokud firmě nebo zvolenému období chybí některý předpoklad (otevřené účetní období, potvrzený právní profil spolku, osy činnosti, druhu činnosti a daňového režimu v dimenzích, povolený výnosový účet v účtové osnově), formulář ho hned nahoře vypíše s odkazem, kde ho doplnit.

Vyberte otevřené účetní období, účetní datum, splatnost a období, za které příspěvek náleží. Vyplňte označení příspěvku (například `CP-2027`), částku, pohledávkový a výnosový účet z rozvrhu, odkaz na titul a doložení částky. Jakmile začnete formulář vyplňovat, vypíše pod sebou všechno, co ještě chybí.

Stejné označení nejde u člena předepsat dvakrát na překrývající se období. Po stornu předpisu lze stejné označení a období předepsat znovu. Členství musí být aktivní k účetnímu datu a po celé období příspěvku, od prvního do posledního dne. Pokud není, formulář na to upozorní ještě před náhledem a náhled skončí chybou.

Výnos se účtuje na účet povolený rolí přijatých členských příspěvků; ve výchozím nastavení jde o účet 684 nebo jeho analytiku. Když období příspěvku přesahuje rozvahový den účetního období, náhled to oznámí a krok uzávěrky **Časové rozlišení** odloží část připadající na další období na účet 384 a v dalším období ji rozpustí.

Vyberte nebo zkontrolujte činnost, druh činnosti a daňový režim z účetních dimenzí firmy. Sazby se spravují pouze v samostatném číselníku na stránce členů s oprávněním ke správě účetních šablon. Použití sazby vyžaduje oprávnění k zaúčtování. Sazba bez dimenzí ponechá ruční výběr; přepis odlišných dimenzí vyžaduje potvrzení. Předvyplnění je možné jen výslovně nastavenou sazbou, nikoli podle výnosového účtu. V náhledu zkontrolujte také ověřené členění; bez jeho shody s vaší volbou nelze předpis potvrdit.

Účetní období musí mít potvrzený právní profil spolku s hlavní nepodnikatelskou činností. Zkontrolujte také revizi členství platnou k účetnímu datu. Samotné číslo účtu ani označení organizace neprokazuje právní titul.

Zvolte **Zobrazit náhled**, zkontrolujte člena, období, částku a účty a použijte **Potvrdit a zapsat**. Potvrzení vytvoří pohledávku a navazující účetní doklad v jednom kroku. Změna formuláře vyžaduje nový náhled. Zápis vyžaduje přihlášenou relaci a oprávnění k zaúčtování.

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

Seznam můžete prohledat podle části jména kontaktu nebo členského čísla. Zvolte rok příspěvku a případně přesné označení ve výběru titulů. Vyhledávání se použije po krátké prodlevě, ostatní filtry ihned. Aktivní filtry jsou vidět jako odstranitelné štítky a ukládají se do adresy stránky. Rok vybírá příspěvky, jejichž období do zvoleného roku zasahuje. Pro zobrazení všech období jednoho titulu vyberte označení, rok nechte prázdný a stav úhrady ponechte **Vše**. Filtrování podle stavu úhrady vyžaduje konkrétní rok; při prázdném roce se doplní aktuální rok. Přehled všech období může nad velkou historií trvat déle. Volba roku nemění účetní datum ani stav členství.

U člena uvidíte částku předepsaných aktivních příspěvků za vybraný titul nebo rok a stav **Uhrazeno**, **Neuhrazeno**, **K prověření** nebo **Bez předpisu pro zvolený titul nebo rok**. Stornované předpisy se do těchto součtů nezahrnují. Prokázaná úhrada a zůstatek vycházejí ze stejného nativního párování jako detail člena.

Předpis bez spárované úhrady se zobrazí jako neuhrazený s nulovou úhradou a plným zůstatkem. Pokud jsou účetní důkazy poškozené nebo je párování nejednoznačné, úhrada a zůstatek se zobrazí jako neurčené, nikoli jako potvrzený dluh. Filtr **Neuhrazeno** proto zahrnuje předpisy bez spárované úhrady i předpisy uhrazené jen zčásti; předpisy k prověření mají vlastní filtr **K prověření**. Filtry se uplatní před stránkováním celého seznamu.

Změna filtrů zruší zaškrtnuté členy pro novou dávku a oznámí to. Obnova dat ověří celý výběr včetně členů z dalších stránek a zachová ty, kteří stále odpovídají filtrům. Pouhé použití nezměněných filtrů výběr zachová. Již uložená dávka se tím nemění. Detail otevřeného člena stále ukazuje celou jeho historii a předpisy, nezávisle na filtrech seznamu.

### Párování a kontrola období

Pohledávky se zobrazují po stránkách. Přehled rozlišuje předepsanou částku a zůstatek k úhradě. Úhrada je doložená teprve přes skutečné párování s nativním bankovním nebo pokladním dokladem; předpis bez spárované úhrady má zůstatek ve výši celé částky.

Párování se nedělá na této stránce. Otevřete opis účtu pohledávky v hlavní knize, přepněte na **Otevřené položky - párování** a spojte řádek předpisu s řádkem platby do okruhu ([kap. 55.8.5.1](55_Hlavni_kniha.md)). Úhrada se prokáže jen u okruhu, ve kterém je jediná pohledávka z této evidence.

U poškozených důkazů nebo nejednoznačného párování se částka nevydává za potvrzený dluh. Opravte párování v opisu účtu pohledávky.

Kontrola vybraného účetního období pod seznamem členů porovnává členské pohledávky, potvrzené operace a pohledávkové i výnosové řádky účetního deníku. Po zápisu předpisu, storna nebo revize se dřívější výsledek kontroly skryje; spusťte ji znovu. Případný rozdíl vyžaduje kontrolu původu dokladů.

## 120.4 Storno a opakování požadavku

Storno zahajte z konkrétní pohledávky. Zvolte účetní datum v otevřeném období a doložte důvod. Náhled vychází z původní potvrzené operace. Po výslovném potvrzení vznikne navazující storno; původní pohledávka i její historie zůstávají dohledatelné.

Při nejistém výsledku odeslání obnovte přehled nebo opakujte stejný zmrazený požadavek. Stejný požadavek se stejným identifikátorem nevytvoří druhý předpis. Před založením nového požadavku ověřte, zda se původní operace již nezapsala.

## 120.5 Čtení přes veřejné API a MCP

Kurátorované katalogy členských rolí a pohledávek jsou dostupné s oprávněním ke čtení účetnictví také přes API token a MCP. Seznamy i detaily používají stejnou obálku; stránka obsahuje nejvýše 100 záznamů a pokračovací kurzor je vázaný na firmu a filtry. Výstup neobsahuje jména, členská čísla, právní podklady ani interní otisky a aktéry.

U členských rolí zadejte rozhodné datum `as_of` a případně okamžik tehdejší znalosti `known_at`. Pokud jej vynecháte, připne jej první stránka. Později zaznamenaná revize tak nezmění navazující stránky téhož čtení. U pohledávek se čte aktuální účetní stav a doložená nativní bankovní nebo pokladní párování; historický okamžik úhrad se nepřijímá. U pohledávek k prověření zůstávají uhrazená i otevřená částka `null`. Katalog sám nepotvrzuje osvobození příjmu ani oprávnění k finanční operaci.

Historické role vyžadují průkazný okamžik zaznamenání. Pokud jej starší záznam nemá doložený, časový katalog vrátí chybu místo odhadování časové zóny nebo vynechání člena. U pohledávky může být čas zaznamenání `null` se stavem `unproven`; ověření její částky a účetních podkladů tím zůstává samostatné. Původní časové údaje se při aktualizaci ani anonymizované kopii nepřepočítávají odhadem.
