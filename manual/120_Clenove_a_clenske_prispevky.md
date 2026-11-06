# 120. Členové a členské příspěvky

V podvojném účetnictví podle vyhlášky 504 otevřete **Účetnictví → Členské příspěvky spolku**. Evidence spojuje člena spolku s kontaktem, právním titulem členství a účetně doloženými předpisy příspěvků.

Postup je vždy stejný: založte člena, zapište mu první revizi členství a teprve potom předpis příspěvku. Dokud není evidován žádný člen, stránka tyto tři kroky vypíše.

## 120.1 Založení a historie člena

Vyberte kontakt a vyplňte jedinečné členské číslo. Kontakt i číslo smí v organizaci patřit jen jednomu členovi. Založení člena samo nevytvoří pohledávku ani účetní zápis. V detailu přidejte první revizi členství s datem účinnosti, odkazem na stanovy nebo rozhodnutí a doložením členského vztahu bez vzájemného protiplnění. První revize musí mít stav **Aktivní**.

Změny stavu se zapisují jako další revize přes **Změnit stav členství** v detailu člena. Nová revize musí začínat po datu účinnosti poslední revize. Původní historie zůstává zachovaná. Budoucí pozastavení nebo ukončení nemění dnešní stav ani starší předpisy. Detail odlišuje dnešní stav od budoucích změn.

Člena nejde smazat, přiřazený kontakt ani členské číslo nejde změnit a zapsanou revizi nejde vzít zpět. Před založením člena i před potvrzením revize proto údaje zkontrolujte.

## 120.2 Předpis příspěvku

### Sazby členských příspěvků

V rozbalovací části **Sazby členských příspěvků** založte šablony své organizace. Každá sazba obsahuje název, částku na jednoho člena, účet pohledávky, výnosový účet 684 nebo jeho analytiku, označení příspěvku, výchozí délku období a texty podkladu. Analytiky lze členit podle vlastních potřeb; nemusí odpovídat částce příspěvku. Vstupní poplatek může mít vlastní sazbu a vlastní označení. Rodinná částka za domácnost se touto sazbou nerozděluje.

U nového předpisu vyberte sazbu a zvolte **Použít sazbu**. Výchozí délka (měsíc, čtvrtletí, pololetí, rok) se počítá od vyplněného začátku příspěvku, jinak od účetního data. Konec připadne na den před měsíčním výročím; pokud výroční den v cílovém měsíci neexistuje, použije se poslední den cílového měsíce a od něj předchozí den. Volba ručního období data nemění. Před náhledem vždy zkontrolujte a případně upravte obě data, částku, účty a texty. Délka příspěvku neurčuje platnost členství ani automaticky nezapisuje jeho prodloužení či ukončení. Předpis lze zadat také bez sazby.

Sazba smí předvyplnit všechny tři účetní dimenze, pokud je v ní firma výslovně nastavila. Ve formuláři zůstávají viditelné a upravitelné a náhled je ověří stejně jako ruční volbu. Z výnosového účtu se dimenze automaticky neodvozují.

Změna sazby nepřepisuje rozepsaný ani zaúčtovaný předpis. Pro změnu rozepsaného předpisu sazbu znovu výslovně použijte. Dřívější předpisy drží skutečně potvrzené částky a účetní podklady. Nepoužívanou sazbu vyřaďte vypnutím **Nabízet pro nové předpisy**; zůstane dohledatelná přes **Zobrazit i vyřazené sazby**. Současnou změnu stejné sazby z jiného okna je třeba nejdřív znovu načíst.

### Zadání a potvrzení

Zvolte **Nový předpis příspěvku**. Pokud firmě nebo zvolenému období chybí některý předpoklad (otevřené účetní období, potvrzený právní profil spolku, osy činnosti, druhu činnosti a daňového režimu v dimenzích, výnosový účet 684 v účtové osnově), formulář ho hned nahoře vypíše s odkazem, kde ho doplnit.

Vyberte otevřené účetní období, účetní datum, splatnost a období, za které příspěvek náleží. Vyplňte označení příspěvku (například `CP-2027`), částku, pohledávkový a výnosový účet z rozvrhu, odkaz na titul a doložení částky. Jakmile začnete formulář vyplňovat, vypíše pod sebou všechno, co ještě chybí.

Stejné označení nejde u člena předepsat dvakrát na překrývající se období. Po stornu předpisu lze stejné označení a období předepsat znovu. Členství musí být aktivní k účetnímu datu a po celé období příspěvku, od prvního do posledního dne. Pokud není, formulář na to upozorní ještě před náhledem a náhled skončí chybou.

Výnos se účtuje na účet 684 (přijaté členské příspěvky) nebo jeho analytiku. Když období příspěvku přesahuje rozvahový den účetního období, náhled to oznámí a krok uzávěrky **Časové rozlišení** odloží část připadající na další období na účet 384 a v dalším období ji rozpustí.

Vyberte nebo zkontrolujte činnost, druh činnosti a daňový režim z účetních dimenzí firmy. Předvyplnění je možné jen výslovně nastavenou sazbou, nikoli podle výnosového účtu. V náhledu zkontrolujte také ověřené členění; bez jeho shody s vaší volbou nelze předpis potvrdit.

Účetní období musí mít potvrzený právní profil spolku s hlavní nepodnikatelskou činností. Zkontrolujte také revizi členství platnou k účetnímu datu. Samotné číslo účtu ani označení organizace neprokazuje právní titul.

Zvolte **Zobrazit náhled**, zkontrolujte člena, období, částku a účty a použijte **Potvrdit a zapsat**. Potvrzení vytvoří pohledávku a navazující účetní doklad v jednom kroku. Změna formuláře vyžaduje nový náhled. Zápis vyžaduje přihlášenou relaci a oprávnění k zaúčtování.

Členský předpis není automatickým rozhodnutím o osvobození příjmu. Daňový režim doložte v daňovém posouzení příslušného období.

## 120.3 Úhrady a kontrola zůstatku

Pohledávky se zobrazují po stránkách. Přehled rozlišuje předepsanou částku a prokázaný otevřený zůstatek. Úhrada je doložená teprve přes skutečné párování s nativním bankovním nebo pokladním dokladem.

Párování se nedělá na této stránce. Otevřete opis účtu pohledávky v hlavní knize, přepněte na **Otevřené položky - párování** a spojte řádek předpisu s řádkem platby do okruhu ([kap. 55.8.5.1](55_Hlavni_kniha.md)). Úhrada se prokáže jen u okruhu, ve kterém je jediná pohledávka z této evidence.

U neprokázané nebo nejednoznačné úhrady se částka nevydává za potvrzený dluh. Opravte párování v opisu účtu pohledávky.

Kontrola vybraného účetního období pod seznamem členů porovnává členské pohledávky, potvrzené operace a pohledávkové i výnosové řádky účetního deníku. Po zápisu předpisu, storna nebo revize se dřívější výsledek kontroly skryje; spusťte ji znovu. Případný rozdíl vyžaduje kontrolu původu dokladů.

## 120.4 Storno a opakování požadavku

Storno zahajte z konkrétní pohledávky. Zvolte účetní datum v otevřeném období a doložte důvod. Náhled vychází z původní potvrzené operace. Po výslovném potvrzení vznikne navazující storno; původní pohledávka i její historie zůstávají dohledatelné.

Při nejistém výsledku odeslání obnovte přehled nebo opakujte stejný zmrazený požadavek. Stejný požadavek se stejným identifikátorem nevytvoří druhý předpis. Před založením nového požadavku ověřte, zda se původní operace již nezapsala.

## 120.5 Čtení přes veřejné API a MCP

Kurátorované katalogy členských rolí a pohledávek jsou dostupné s oprávněním ke čtení účetnictví také přes API token a MCP. Seznamy i detaily používají stejnou obálku; stránka obsahuje nejvýše 100 záznamů a pokračovací kurzor je vázaný na firmu a filtry. Výstup neobsahuje jména, členská čísla, právní podklady ani interní otisky a aktéry.

U členských rolí zadejte rozhodné datum `as_of` a případně okamžik tehdejší znalosti `known_at`. Pokud jej vynecháte, připne jej první stránka. Později zaznamenaná revize tak nezmění navazující stránky téhož čtení. U pohledávek se čte aktuální účetní stav a doložená nativní bankovní nebo pokladní párování; historický okamžik úhrad se nepřijímá. Neprokázané úhrady a otevřené částky zůstávají `null`. Katalog sám nepotvrzuje osvobození příjmu ani oprávnění k finanční operaci.

Historické role vyžadují průkazný okamžik zaznamenání. Pokud jej starší záznam nemá doložený, časový katalog vrátí chybu místo odhadování časové zóny nebo vynechání člena. U pohledávky může být čas zaznamenání `null` se stavem `unproven`; ověření její částky a účetních podkladů tím zůstává samostatné. Původní časové údaje se při aktualizaci ani anonymizované kopii nepřepočítávají odhadem.
