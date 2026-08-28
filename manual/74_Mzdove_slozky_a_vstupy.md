# Mzdové složky a vstupy

## 74.1 Účel

Mzdové složky definují význam pravidelných a jednorázových plnění, náhrad a korekcí. Vstupy přiřazují konkrétní hodnotu zaměstnanci, vztahu a období.

## 74.2 Předpoklady a oprávnění

Musí existovat aktivní vztah a správné období. Uživatel potřebuje mzdové oprávnění a podklad pro částku, jednotky, účinnost a případné daňové či pojistné zacházení.

## 74.3 Krokový postup

1. Otevřete **Mzdy → Mzdové složky a vstupy** a ověřte význam dostupných složek.
2. Pravidelnou složku nastavte s datem účinnosti u vztahu; jednorázovou vložte do konkrétního měsíce.
3. Vyplňte částku nebo jednotky v očekávaném formátu a uložte.
4. Zkontrolujte návaznost benefitů, cestovních náhrad, absencí a srážek v jejich vlastních agendách.
5. Před výpočtem porovnejte soupis vstupů s podklady a odstraňte duplicity.

## 74.4 Stavy

Budoucí pravidelná složka čeká na účinnost, aktivní se použije pro rozhodné období a ukončená zůstává v historii. Jednorázový vstup čeká na běh. Po uzavření období jej nepřepisujte bez řízené opravy.

## 74.5 Kontroly a bezpečnost

Kontrolujte znaménko, jednotku, období, vztah a klasifikaci plnění. Obecnou složku nepoužívejte k obcházení nepodporovaného právního režimu. Odkaz na podklad je volitelný důkaz; hodnota, období a druh plnění jsou skutečné vstupy.

## 74.6 Časté chyby

- Jednorázová složka zadaná jako pravidelná.
- Vstup přiřazený jinému souběžnému vztahu.
- Duplicitní import a ruční zadání stejné částky.
- Změna vstupu bez nového výpočtu otevřeného běhu.

## 74.7 Návaznosti

Hromadné zadání nabízí [rychlý měsíční vstup](62_Rychly_mesicni_vstup.md), speciální plnění [koše benefitů](72_Kose_benefitu.md) a vše zpracuje [mzdový běh](63_Mzdove_behy.md).



## 74.8 Podrobný pracovní postup a kontroly

V **Mzdy → Mzdové složky a vstupy** jsou běžnými záložkami oddělené:

- katalog mzdových složek;
- pravidelné předpisy;
- jednorázové měsíční vstupy;
- CSV/XLSX import s povinným náhledem před uložením.

Výchozí složky používají české kódy bez diakritiky, aby byly bezpečné i pro
CSV a jiné strojové zpracování. Patří mezi ně například `MZDA_MESICNI`,
`MZDA_HODINOVA`, `ODMENA`, `NAHRADA_MZDY`, `NEPENEZNI_PRIJEM`,
`PRISPEVEK_STRAVOVANI` a `CESTOVNI_NAHRADA`. Stejné kódy používej také ve
sloupci `component_code` importovaného souboru.

Zákonné příplatky podle § 114 až § 118 mají vlastní složky
`PRIPLATEK_PRESCAS`, `PRIPLATEK_SVATEK`, `PRIPLATEK_NOCNI`,
`PRIPLATEK_VIKEND` a `PRIPLATEK_ZTIZENE_PROSTREDI`. **Nezadávají se ručně ani
importem** — vznikají samy při schválení měsíce
[docházky](60_Dochazka_a_smeny.md#6093-zakonne-priplatky-ke-mzde-114-az-118)
z evidovaných hodin a jejich příznaků, aby šel nárok doložit z mzdového listu
(§ 142 odst. 5 zákoníku práce). Výjimkou je přesčas zadaný hodinami v
[rychlém měsíčním vstupu](62_Rychly_mesicni_vstup.md), který příplatkovou
složku založí také. Sazbu berou z legislativní sady, případně ze sjednané
zásady pracovního vztahu; ručně se u nich sazba nepřepisuje. U nové vlastní složky zadej
nejprve název; kód se z něj automaticky vytvoří bez diakritiky. Dokud jej ručně
neupravíš, sleduje změny názvu. Po uložení už kód ani začátek platnosti změnit
nelze; další účinnost se zakládá jako nová verze.

Jednorázový měsíční vstup vzniká jako **koncept** — teprve tak jde ještě
upravit i zrušit. Schválení je to, co vstup zmrazí. Schvalovat po jednom ale
nemusíte: tlačítko **Schválit vše (N)** schválí najednou až pět set konceptů
zvoleného měsíce a už schválený vstup jen přeskočí. Totéž tlačítko nabízí
mzdový běh přímo u blokace, když do něj nějaký koncept zbyl. Hromadné zadání
přes [rychlý měsíční vstup](62_Rychly_mesicni_vstup.md) uloží řádky rovnou
jako schválené, má-li k tomu uživatel oprávnění.

Každá složka samostatně určuje dopad do daně, sociálního a zdravotního
pojištění, průměrného výdělku, exekučního základu, JMHZ, statistiky a
účetnictví. Schválený vstup si uloží neměnný snapshot této klasifikace; pozdější
změna katalogu proto nepřepíše již zpracované období.

Omylem založenou vlastní složku nebo pravidelný předpis lze tlačítkem
**Smazat** odstranit, dokud ještě nevstoupily do žádného mzdového vstupu,
výpočtu ani jiné navazující evidence. Před odstraněním se vždy zobrazí
potvrzení. Jakmile byl záznam použit, aplikace smazání odmítne a vysvětlí, zda
je potřeba ukončit jeho platnost nebo jej deaktivovat; již zpracovaná historie
se nemaže.

## 74.9 Povinné spoření u rizikové práce

Panel **Povinné spoření u rizikové práce** slouží pouze pro práce 3. kategorie,
u nichž je rozhodným faktorem vibrace, chlad, teplo nebo dynamická fyzická
zátěž velkými svalovými skupinami. Nestačí obecné označení vztahu jako
rizikového. U každého dotčeného vztahu proto vyberte konkrétní zákonný faktor
a za měsíc zadejte počet rozhodných osmin směny. Celá osmihodinová směna má
osm osmin; u směny jiné délky se každá započatá hodina počítá jako jedna
osmina. Rozhodný minimální rozsah směn, sazba příspěvku, datum účinnosti i
splatnost určuje pro dané období účinný legislativní ruleset. Tyto hodnoty
uživatel ručně nepřepisuje; zadává pouze skutečný rozsah směn a podklady
konkrétního pracovního vztahu.

Nejdříve v **Mzdy → Nastavení mezd → Účty institucí** založte penzijní
společnost jako jiného příjemce a ověřte její účet. Číslo účtu je v katalogu
šifrované. Do schváleného měsíčního podkladu se připne identifikátor, verze,
hash a maskovaná podoba účtu; pozdější změna katalogu tedy nezmění již
zmrazený běh. V panelu dále uveďte identifikaci smlouvy nebo produktu a podle
pokynů penzijní společnosti variabilní či specifický symbol a zprávu pro
příjemce. Odkaz na podklad zůstává nepovinný.

Datum **Právo uplatněno dne** určuje první měsíc nároku. Oznámí-li zaměstnanec
údaje během aktuálního měsíce, nejde o chybu: aplikace uloží kontrolovatelný
stav bez příspěvku a nárok začne až následující měsíc. Datum, kdy byl
zaměstnanec informován, eviduje samostatnou informační povinnost. Chybějící
datum výpočet 4 % nezmění, ale mzdový běh zobrazí srozumitelné varování.

Podklady nejprve uložte jako koncept a po kontrole je schvalte. Schválený
záznam se už nepřepisuje; oprava založí novou revizi a původní zůstane v
auditní historii. Mzdový běh zmrazí použitý ruleset spolu s výsledkem, takže
pozdější legislativní změna nepřepíše sazbu, minimum směn, účinnost ani
splatnost již vypočteného období. Výpočet použije sazbu z nezastropovaného
vyměřovacího základu a výsledek zaokrouhlí nahoru na celé koruny. Chybí-li
u historického běhu tento podklad nebo je poškozený, aplikace přepočet zablokuje
pro ruční posouzení; nikdy místo něj nedosadí dnešní parametry. Uhrazení potvrďte
až podle skutečného bankovního pohybu.
V podvojném účetnictví se příspěvek účtuje jako zákonný sociální náklad
zaměstnavatele proti závazku vůči penzijní společnosti (výchozí kontace
527 / 379). Zaměstnanci se nevyplácí, takže nejde na účet čistých mezd, a
penzijní společnost není institucí sociálního ani zdravotního pojištění, takže
nejde ani na 336. Předkontaci lze změnit v
[Nastavení mezd](73_Nastaveni_mezd.md#7381-predkontace-pro-zvlastni-mzdove-situace).

Po schválení mzdové revize aplikace vytvoří samostatný závazek **Povinné
spoření u rizikové práce** v Mzdových příkazech. Odtud jej zařaďte do ABO
nebo SEPA dávky stejně jako ostatní mzdové odvody. Za uhrazený se považuje až
po spárování bankovní transakce nebo pokladního dokladu; v panelu podkladů se
stav ručně nepřepíná.
