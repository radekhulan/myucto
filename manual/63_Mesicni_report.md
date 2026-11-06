# 63. Měsíční přehled

> Manažerský report pro klienta, který se skládá z již existujících účetních a daňových sestav: náhled, PDF, odeslání e-mailem a historie odeslání. Pro externí účetní, která měsíčně informuje klienta o stavu jeho účetnictví.

## 63.1 Kdy to potřebujete

Kapitolu otevřete, když:

- skončil měsíc a máte klientovi poslat přehled výsledku, DPH a pohledávek po splatnosti,
- si chcete PDF přehledu jen stáhnout nebo prohlédnout,
- hledáte, komu a kdy jste přehled za určitý měsíc poslali.

### 63.1.1 Kdy co udělat

<!-- cols: 26 44 30 -->
| Kdy | Co udělat | Kde v aplikaci |
|---|---|---|
| po vyřešení měsíční kontroly | Připravit a odeslat přehled klientovi | `Účetnictví → Měsíční přehled`, [§ 63.3](#633-krok-za-krokem-sestaveni-a-odeslani-prehledu) |
| když klient přehled nedostal | Dohledat historii odeslání | tabulka **Historie odeslání** na téže stránce |
| na vyžádání | Stáhnout PDF bez odeslání | tlačítko **Stáhnout PDF** |

## 63.2 Než začnete

1. **Podvojné účetnictví a oprávnění.** Přehled je dostupný jen firmě v podvojném účetnictví. Náhled, PDF a historii vidí čtenář s právem číst účetnictví. Odeslání vyžaduje právo zápisu do účetnictví. Uživatel jen pro čtení uvidí hlášku **Máte oprávnění pouze pro čtení - odeslání klientovi je zakázáno.**
2. **Účetní období pro rozhodné datum.** Pro měsíc musí existovat účetní období. Bez něj report nelze sestavit.
3. **Nastavené odesílání e-mailů.** Odeslání používá poštovní nastavení aplikace.
4. **Hotová měsíční práce.** Dokončete [K doúčtování](54_Rucni_fronta_doctovani.md), projděte [Úplnost dokladů](61_Uplnost_dokladu.md) a [Měsíční kontrolu](62_Mesicni_kontrola.md).

> [!WARNING]
> Jde o informační manažerský přehled. Není to účetní závěrka, daňový doklad ani důkaz, že byly provedeny měsíční kontroly. Report použije aktuální stav dat v okamžiku generování.

## 63.3 Krok za krokem: sestavení a odeslání přehledu

1. Otevřete `Účetnictví → Měsíční přehled`.
2. V poli **Období** zvolte rok a měsíc.
3. Ověřte v náhledu měsíc a rozhodné datum (dřívější z posledního dne měsíce a dnešního dne).
4. Volitelně doplňte **Komentář účetní**. Zobrazí se v PDF i v e-mailu. Mají-li doklady období od–do a chcete je rozložit do měsíců, zaškrtněte **Průběžné časové rozlišení** ([§ 63.6.2.1](#63621-vysledovka-za-mesic)).
5. Klikněte na **Obnovit náhled** a zkontrolujte KPI: **Výsledek hospodaření (YTD)**, **DPH k úhradě** (nebo **Nadměrný odpočet**) s termínem podání, **Pohledávky po splatnosti** a **Závazky po splatnosti**.
6. Ověřte výsledovku, rozvahu, saldokonto a DPH v jejich samostatných sestavách.
7. Klikněte na **Stáhnout PDF** a projděte i sekce, které webový náhled nezobrazuje celé (plná rozvaha, kumulovaná výsledovka, nadcházející termíny).
8. Klikněte na **Odeslat klientovi**.
9. Do pole **Komu** zadejte jednu či více adres oddělených čárkou, středníkem nebo mezerou. Podle potřeby vyplňte **Kopie (CC)**.
10. Klikněte na **Odeslat**.

**Jak poznáte, že je hotovo:** Stránka hlásí **Přehled odeslán (N příjemců).** a v tabulce **Historie odeslání** přibyl řádek se sloupci **Období**, **Komu**, **Odeslal(a)**, **Kdy** a odkazem **Zobrazit** na archivované PDF.

> [!TIP]
> Průkazným artefaktem konkrétního odeslání je archivované PDF. Nový náhled téhož měsíce může po pozdějších opravách účetnictví obsahovat jiné hodnoty.

## 63.4 Krok za krokem: dohledání odeslaného přehledu

1. Otevřete `Účetnictví → Měsíční přehled` a sjeďte k tabulce **Historie odeslání**.
2. Najděte řádek podle sloupce **Období** nebo **Komu**.
3. Klikněte na **Zobrazit** u archivovaného dokumentu.

**Jak poznáte, že je hotovo:** Otevře se PDF, které bylo klientovi odesláno. Historie je oddělená pro každou firmu a řadí záznamy od nejnovějšího.

## 63.5 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Report nelze sestavit | Pro rozhodné datum neexistuje účetní období. Budoucí měsíc se neočekávaně nepromítne dopředu, stav se ořízne na dnešek. | Založte účetní období, nebo zvolte měsíc, ke kterému období existuje. |
| **Zadejte alespoň jednoho příjemce.** | Pole **Komu** je prázdné nebo obsahuje neplatnou adresu | Opravte adresy. Server každou validuje. |
| Odeslání e-mailu selhalo | Poštovní server zprávu nepřevzal | Zkontrolujte nastavení e-mailu a odešlete znovu. Historie se neuloží, chyba je v auditní stopě. |
| Odkaz na dokument v historii chybí | E-mail se odeslal, ale archivace do Dokumentů selhala | Odeslání zůstává platné. PDF znovu stáhněte a uložte ručně. |
| Sekce DPH v PDF chybí | U neplátce se sekce nezobrazuje jako daň k úhradě. U plátce se ji nepodařilo sestavit. | Zkontrolujte samostatné výkazy DPH, chybějící DPH v reportu není důkaz, že firma nemá daňovou povinnost. |
| KPI po splatnosti ukazuje jen 10 položek | Report vybírá nejvýše 10 položek s nejdelším prodlením | Úplný seznam najdete v [Saldokontu](60_Saldokonto.md). |
| Tlačítko **Odeslat klientovi** nejde použít | Máte právo jen pro čtení | Požádejte správce o právo zápisu do účetnictví. |

## 63.6 Podrobnosti a pravidla

Pro účetní rámec podle vyhlášky č. 504/2002 Sb. přehled zobrazuje výsledovku za měsíc i od začátku účetního období ve sloupcích **Hlavní činnost, Hospodářská činnost a Celkem**. Měsíční částky jsou rozdílem kumulovaných sestav, také při hospodářském roce. Chybějící klasifikace činnosti na zaúčtovaném řádku sestavení zastaví.

Rozvahová část tohoto manažerského přehledu pro rámec 504 obsahuje počáteční a konečné zůstatky účtů. Kreditní zůstatky jsou záporné. Nevypořádaný účet 931 se zobrazí s upozorněním a odsouhlasením; nezmizí z přehledu ani se automaticky nepřevede na jiný účet. Oficiální rozvahu sestavíte v samostatné sestavě **Rozvaha**.

### 63.6.1 Volba měsíce a rozhodné datum

Přehled nevytváří vlastní paralelní výpočty. Všechny části přebírá ze stejných služeb jako samostatné účetní a daňové sestavy.

Vyberte rok a měsíc v rozsahu 2000-2100. Systém určí:

- první a poslední den měsíce,
- **rozhodné datum** jako dřívější z posledního dne měsíce a dnešního dne,
- účetní období, do kterého rozhodné datum patří.

Pokud pro rozhodné datum účetní období neexistuje, report nelze sestavit.
Budoucí měsíc se proto neočekávaně „nepromítne dopředu“: stav se ořízne na
dnešek a musí pro něj existovat účetní období.

Volitelný **Komentář účetní** se přidá do náhledu dat, do PDF a do textu
odesílaného e-mailu. Komentář není účetní zápis ani trvalá anotace sestavy;
uloží se až jako součást záznamu o skutečném odeslání.

### 63.6.2 Jak se jednotlivé části počítají

#### 63.6.2.1 Výsledovka za měsíc

Zdroj je stejná služba jako samostatná Výsledovka:

1. sestaví kumulovanou výsledovku od začátku fiskálního období do posledního
   dne měsíce,
2. sestaví druhý kumulovaný snímek ke dni před prvním dnem měsíce,
3. podle shodného kódu řádku odečte starší hodnotu od novější.

Výsledkem je obrat samotného měsíce. V prvním měsíci fiskálního období není co
odečíst, proto je měsíční hodnota shodná s YTD. Metadata řádků, hierarchie a
mapování účtů se nepřepočítávají v reportu; přebírají se ze samostatné Výsledovky.

KPI **Výsledek hospodaření (YTD)** je kontrolní hodnota výsledku za období, nikoli prostý součet libovolně
zobrazených detailních řádků na stránce.

Volba **Průběžné časové rozlišení** platí pro náhled, PDF i odeslání a sestaví výsledovku
i rozvahu stejně jako samostatné výkazy ([§ 58.7.7](58_Vysledovka_druhova.md#5877-prubezne-casove-rozliseni)).
Oba kumulované snímky se počítají s rozlišením ke svému dni, takže v měsíci je jen poměrná
část dokladu připadající na tento měsíc. Úprava není zaúčtovaná v deníku a PDF na ni
upozorňuje. Rámec 504 volbu nenabízí. Zapamatovaná volba je společná s Výsledovkou a Rozvahou.

#### 63.6.2.2 Rozvaha

Rozvaha se sestaví ke stejnému rozhodnému datu a stejnou službou jako
samostatná Rozvaha. PDF obsahuje aktiva, pasiva a kontrolu, zda se čistá aktiva
rovnají pasivům. Webový náhled zobrazuje hlavně KPI a měsíční výsledovku;
plná rozvaha je součástí staženého nebo odeslaného PDF.

#### 63.6.2.3 Pohledávky a závazky po splatnosti

Zdroj je historické saldokonto ke dni konce reportu:

- pohledávky z účtu 311,
- závazky z účtu 321.

Do reportu se za každou stranu vybere nejvýše **10 položek** s kladným počtem
dní po splatnosti, seřazených od nejdelšího prodlení. Částka je zbývající
zůstatek v CZK. KPI v náhledu ukazuje počet položek v tomto omezeném top
seznamu, nikoli počet všech otevřených položek firmy.

#### 63.6.2.4 DPH

U neplátce se sekce nezobrazí jako daň k úhradě. U plátce server sestaví
read-only náhled přiznání k DPH pro zvolený rok a měsíc a převezme:

- období a jeho typ,
- daň k úhradě nebo nadměrný odpočet,
- termín podání.

Tento výpočet nevytváří ani nearchivuje snapshot daňového podání. Pokud se DPH
sekci nepodaří sestavit, zbytek měsíčního reportu zůstane dostupný a DPH se
vynechá. Chybějící DPH v reportu proto není důkaz, že firma nemá daňovou
povinnost; ověřte samostatné výkazy.

#### 63.6.2.5 Nadcházející termíny

PDF přebírá nejvýše osm nadcházejících předpisů ze služby daňových záloh.
Uvádí typ, datum, částku, stav a informaci, zda je termín po splatnosti.

### 63.6.3 Rozdíl mezi náhledem a PDF

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

Soubor se stahuje pod názvem ve tvaru `mesicni-prehled-RRRR-MM.pdf`.

### 63.6.4 Odeslání klientovi

Do polí **Komu** a **Kopie (CC)** lze zadat více adres oddělených čárkou,
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

### 63.6.5 Historie odeslání

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

## 63.7 Související kapitoly

- [Měsíční kontrola](62_Mesicni_kontrola.md) - brána před odesláním
- [Výsledovka druhová](58_Vysledovka_druhova.md), [Rozvaha](57_Rozvaha.md), [Saldokonto](60_Saldokonto.md) - zdroje částí reportu
- [Úplnost dokladů](61_Uplnost_dokladu.md) a [K doúčtování](54_Rucni_fronta_doctovani.md)
- [Průvodce účetního](50_Pruvodce_ucetniho.md)

Měsíční přehled není vhodný jako náhrada kompletního závěrkového balíčku ani daňového podání. Jeho účelem je srozumitelně informovat klienta o aktuálním stavu účetnictví.
