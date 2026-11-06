# 117. Granty a účelové dary

V podvojném účetnictví podle vyhlášky 504 otevřete **Účetnictví → Granty a účelové dary**. Přehled spojuje poskytovatele, smluvní účel a schválený rozpočet s účetně doloženým zdrojem a skutečným použitím v potvrzeném daňovém posouzení.

## 117.1 Příprava účetního zdroje

Nejprve zaúčtujte příjem nebo nárok na dotaci či dar běžným účetním postupem. Výsledkové řádky musí mít druh činnosti, činnost a předběžný daňový režim. V daňovém posouzení období doložte právní titul příjmu. U daru potvrďte volbu osvobození a odpovídající účel. Potvrzená volba osvobození zůstává součástí historie.

Ve výběru nového zdroje jsou aktuálně potvrzené provozní dotace a dary. Doložené opravy příjmu patří do téhož zdrojového celku. Přehled jejich hodnoty sčítá v čisté účetní částce.

## 117.2 Potvrzení účelu a rozpočtu

Vyberte otevřené účetní období a zvolte **Nový účelový zdroj**. Vyberte konkrétní účetní zdroj a poskytovatele nebo dárce. Poskytovatele můžete vyhledat podle názvu či jména.

Vyplňte název, účel, odkaz na smlouvu nebo rozhodnutí, schválený rozpočet, časové rozmezí způsobilých nákladů, druhy činností a nákladové účty. Podmínky musí odpovídat doloženému titulu. Uveďte důvod revize a otevřete náhled.

Náhled ukazuje účetně doloženou částku, rozpočet, potvrzené použití a nevyčerpaný rozpočet. Rozpočet může zahrnovat i dosud nečerpanou část schváleného titulu. Ověřte podklady a výslovně potvrďte revizi. Potvrzování podmínek vyžaduje oprávnění k účetní uzávěrce a přihlášenou relaci.

## 117.3 Skutečné použití

Přes **Posoudit daň a použití zdroje** otevřete daňové posouzení vybraného období. Vazbou financování spojte zdroj s konkrétním kladným nákladem a doložte použitou částku. Posouzení kontroluje čistý zdroj po opravách, předchozí použití i čistou nákladovou částku.

U evidovaného účelového zdroje se navíc kontroluje jeho rozpočet, datum nákladu, druh činnosti a účet. Rozpočet je společný pro všechna účetní období. Způsob vyloučení financované daňově uznatelné části nákladu vychází ze stejného potvrzeného posouzení.

Přehled zdroje čte toto potvrzené použití přímo, ať bylo posouzení potvrzené klasicky, nebo stránkovanou cestou J6. Stránkované posouzení se ověřuje proti otisku dnešních účetních podkladů a čte se po ověřených stránkách. Je-li některé předchozí období posouzené stránkovaně, starší klasické posouzení navazujícího období se v přehledu ukáže jako neaktuální; potvrďte je znovu stránkovanou cestou. Samotné potvrzení účelu ani prohlížení přehledu nevytváří účetní zápis.

## 117.4 Opravy a nové revize

Při změně smluvních podmínek zvolte u zdroje **Nová revize podmínek**. Původní poskytovatel a původ zdroje zůstávají zachované. Nové podmínky musí dál připouštět již potvrzené použití. Rozpočet nelze snížit pod čerpanou částku.

Po doloženém záporném opravném řádku zdroje nejprve potvrďte jeho návaznost v daňovém posouzení. Účelové podmínky a rozpočtový limit dál platí. Nová revize evidence následně převezme také opravu zdroje. Náhled ukáže novou čistou účetní částku.

Změna účetnictví nebo odvolání posouzení se v přehledu projeví upozorněním. Dřívější potvrzené částky zůstávají dohledatelné; neaktuální podklady vyžadují kontrolu. Při neověřeném výsledku odeslání obnovte přehled, nebo výslovně opakujte stejné potvrzení. Stejný požadavek nevytvoří další revizi.

## 117.5 Kontrolní podklady a dary

Přehled uvádí název a účel zdroje, historické údaje poskytovatele, rozpočet, účetně doloženou hodnotu a čerpání. Detail použití ukazuje konkrétní datum, nákladový účet, částku, podklad a aktuálnost potvrzení.

U daru jsou dostupné také původní účetní řádky s datem a částkou včetně oprav a potvrzená volba osvobození. Tyto podklady slouží ke kontrole evidence a k přípravě potvrzení externím nástrojem. Použitý kontakt poskytovatele archivujte; jeho historická vazba brání smazání.

Pro potvrzení o daru nabízí REST API a MCP server čtení ověřitelných podkladů zdroje z potvrzeného stránkovaného posouzení J6: datum a částku přijetí daru, právní titul, rozhodnutí o osvobození se zákonným kódem, souhrn čerpání a jednotlivé čerpané náklady. Dárce je uveden jen identifikátorem kontaktu; volné texty a důkazy se nevracejí. Text potvrzení ani dopis aplikace netvoří, připraví je externí nástroj nad těmito podklady. Podrobnosti jsou v kapitolách [REST API](103_API.md) a [MCP server](105_MCP_server.md).
