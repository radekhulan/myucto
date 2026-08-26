# 56. Měsíční přehled

**Cesta: `Účetnictví → Měsíční přehled`**

Měsíční přehled skládá manažerský report pro klienta z již existujících
účetních a daňových sestav. Nevytváří vlastní paralelní výpočty. Lze zobrazit
náhled, stáhnout PDF, odeslat je e-mailem a dohledat historii odeslání.

Je dostupný jen firmě v podvojném účetnictví. Čtenář s právem číst účetnictví
může zobrazit náhled, stáhnout PDF a historii. Odeslání vyžaduje právo zápisu
do účetnictví.

> ⚠️ **Jde o informační manažerský přehled.** Není to účetní závěrka, daňový doklad
> ani důkaz, že byly provedeny měsíční kontroly. Report použije aktuální stav
> dat v okamžiku generování.

## 56.1 Volba měsíce a rozhodné datum

Vyberte rok a měsíc v rozsahu podporovaném serverem (2000–2100). Systém určí:

- první a poslední den měsíce,
- **rozhodné datum** jako dřívější z posledního dne měsíce a dnešního dne,
- účetní období, do kterého rozhodné datum patří.

Pokud pro rozhodné datum účetní období neexistuje, report nelze sestavit.
Budoucí měsíc se proto neočekávaně „nepromítne dopředu“: stav se ořízne na
dnešek a musí pro něj existovat účetní období.

Volitelný **Komentář účetní** se přidá do náhledu dat, do PDF a do textu
odesílaného e-mailu. Komentář není účetní zápis ani trvalá anotace sestavy;
uloží se až jako součást záznamu o skutečném odeslání.

## 56.2 Jak se jednotlivé části počítají

### 56.2.1 Výsledovka za měsíc

Zdroj je stejná služba jako samostatná Výsledovka:

1. sestaví kumulovanou výsledovku od začátku fiskálního období do posledního
   dne měsíce,
2. sestaví druhý kumulovaný snímek ke dni před prvním dnem měsíce,
3. podle shodného kódu řádku odečte starší hodnotu od novější.

Výsledkem je obrat samotného měsíce. V prvním měsíci fiskálního období není co
odečíst, proto je měsíční hodnota shodná s YTD. Metadata řádků, hierarchie a
mapování účtů se nepřepočítávají v reportu; přebírají se z
`FinancialStatementService`.

KPI **Výsledek hospodaření YTD** je kontrolní hodnota
`income_statement_ytd.checks.profit_current`, nikoli prostý součet libovolně
zobrazených detailních řádků na frontendu.

### 56.2.2 Rozvaha

Rozvaha se sestaví ke stejnému rozhodnému datu a stejnou službou jako
samostatná Rozvaha. PDF obsahuje aktiva, pasiva a kontrolu, zda se čistá aktiva
rovnají pasivům. Webový náhled zobrazuje hlavně KPI a měsíční výsledovku;
plná rozvaha je součástí staženého nebo odeslaného PDF.

### 56.2.3 Pohledávky a závazky po splatnosti

Zdroj je historické saldokonto ke dni konce reportu:

- pohledávky z účtu 311,
- závazky z účtu 321.

Do reportu se za každou stranu vybere nejvýše **10 položek** s kladným počtem
dní po splatnosti, seřazených od nejdelšího prodlení. Částka je zbývající
zůstatek v CZK. KPI v náhledu ukazuje počet položek v tomto omezeném top
seznamu, nikoli počet všech otevřených položek firmy.

### 56.2.4 DPH

U neplátce se sekce nezobrazí jako daň k úhradě. U plátce server sestaví
read-only náhled přiznání k DPH pro zvolený rok a měsíc a převezme:

- období a jeho typ,
- daň k úhradě nebo nadměrný odpočet,
- termín podání.

Tento výpočet nevytváří ani nearchivuje snapshot daňového podání. Pokud se DPH
sekci nepodaří sestavit, zbytek měsíčního reportu zůstane dostupný a DPH se
vynechá. Chybějící DPH v reportu proto není důkaz, že firma nemá daňovou
povinnost; ověřte samostatné výkazy.

### 56.2.5 Nadcházející termíny

PDF přebírá nejvýše osm nadcházejících předpisů ze služby daňových záloh.
Uvádí typ, datum, částku, stav a informaci, zda je termín po splatnosti.

## 56.3 Rozdíl mezi náhledem a PDF

Webová stránka zobrazuje:

- výsledek hospodaření YTD,
- DPH k úhradě nebo nadměrný odpočet a termín,
- počet top pohledávek a závazků po splatnosti,
- řádky výsledovky za samotný měsíc,
- seznam top pohledávek a závazků.

PDF navíc obsahuje:

- kumulovanou výsledovku YTD a srovnání s minulým obdobím,
- plnou rozvahu a kontrolu její vyrovnanosti,
- podrobnější tabulky top salda,
- DPH a nadcházející daňové termíny,
- komentář účetní,
- upozornění, že jde o informační přehled.

Soubor se stahuje jako `mesicni-prehled-RRRR-MM.pdf`.

## 56.4 Odeslání klientovi

Do polí **Komu** a **Kopie** lze zadat více adres oddělených čárkou,
středníkem nebo mezerou. Server každou adresu validuje; alespoň jeden hlavní
příjemce je povinný.

Po potvrzení proběhne tento tok:

1. server znovu sestaví data z aktuálního stavu a vyrenderuje PDF,
2. PDF odešle jako přílohu české e-mailové šablony měsíčního přehledu,
3. stejný soubor se pokusí uložit do Dokumentů,
4. uloží záznam historie odeslání s obdobím, příjemci, kopií, komentářem,
   odesílajícím uživatelem, odpovědí SMTP a případným ID dokumentu,
5. zapíše auditní událost.

Pokud odeslání e-mailu selže, historie úspěšného odeslání nevznikne a chyba se
zapíše do auditní stopy. Archivace do Dokumentů je naopak **best effort**:
selže-li až po úspěšném e-mailu, odeslání zůstává platné, historie se uloží s
prázdným ID dokumentu a server zaznamená varování.

SMTP odpověď potvrzuje převzetí zprávy poštovním serverem, nikoli konečné
doručení do schránky příjemce.

## 56.5 Historie odeslání

Historie je oddělená pro každou firmu a řadí záznamy od nejnovějšího. Stránka
načítá standardně posledních 30, server dovoluje nejvýše 100. U řádku je
zobrazeno:

- období,
- hlavní příjemci,
- kdo report odeslal,
- datum odeslání,
- odkaz na archivovaný dokument, pokud archivace uspěla.

Historie neukládá neměnný datový snapshot všech vstupních sestav samostatně;
průkazným artefaktem konkrétního odeslání je archivované PDF. Nový náhled téhož
měsíce může po pozdějších opravách účetnictví obsahovat jiné hodnoty.

## 56.6 Doporučený postup před odesláním

1. Dokončete [K doúčtování](47_Rucni_fronta_doctovani.md).
2. Projděte [Úplnost dokladů](54_Uplnost_dokladu.md) a
   [Měsíční kontrolu](55_Mesicni_kontrola.md).
3. Ověřte výsledovku, rozvahu, saldokonto a DPH v jejich samostatných
   sestavách.
4. V náhledu zkontrolujte měsíc a rozhodné datum a doplňte věcný komentář.
5. Stáhněte PDF a projděte i sekce, které webový náhled nezobrazuje celé.
6. Teprve potom zadejte ověřené adresy a report odešlete.

Měsíční přehled není vhodný jako náhrada kompletního závěrkového balíčku ani
daňového podání. Jeho účelem je srozumitelně informovat klienta o aktuálním
stavu účetnictví.
