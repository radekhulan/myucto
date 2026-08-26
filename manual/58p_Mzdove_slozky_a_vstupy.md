# Mzdové složky a vstupy

## Účel

Mzdové složky definují význam pravidelných a jednorázových plnění, náhrad a korekcí. Vstupy přiřazují konkrétní hodnotu zaměstnanci, vztahu a období.

## Předpoklady a oprávnění

Musí existovat aktivní vztah a správné období. Uživatel potřebuje mzdové oprávnění a podklad pro částku, jednotky, účinnost a případné daňové či pojistné zacházení.

## Krokový postup

1. Otevřete **Mzdy → Mzdové složky a vstupy** a ověřte význam dostupných složek.
2. Pravidelnou složku nastavte s datem účinnosti u vztahu; jednorázovou vložte do konkrétního měsíce.
3. Vyplňte částku nebo jednotky v očekávaném formátu a uložte.
4. Zkontrolujte návaznost benefitů, cestovních náhrad, absencí a srážek v jejich vlastních agendách.
5. Před výpočtem porovnejte soupis vstupů s podklady a odstraňte duplicity.

## Stavy

Budoucí pravidelná složka čeká na účinnost, aktivní se použije pro rozhodné období a ukončená zůstává v historii. Jednorázový vstup čeká na běh. Po uzavření období jej nepřepisujte bez řízené opravy.

## Kontroly a bezpečnost

Kontrolujte znaménko, jednotku, období, vztah a klasifikaci plnění. Obecnou složku nepoužívejte k obcházení nepodporovaného právního režimu. Odkaz na podklad je volitelný důkaz; hodnota, období a druh plnění jsou skutečné vstupy.

## Časté chyby

- Jednorázová složka zadaná jako pravidelná.
- Vstup přiřazený jinému souběžnému vztahu.
- Duplicitní import a ruční zadání stejné částky.
- Změna vstupu bez nového výpočtu otevřeného běhu.

## Návaznosti

Hromadné zadání nabízí [rychlý měsíční vstup](58d_Rychly_mesicni_vstup.md), speciální plnění [koše benefitů](58n_Kose_benefitu.md) a vše zpracuje [mzdový běh](58e_Mzdove_behy.md).



## Podrobný pracovní postup a kontroly

V **Mzdy → Mzdové složky a vstupy** jsou běžnými záložkami oddělené:

- katalog mzdových složek;
- pravidelné předpisy;
- jednorázové měsíční vstupy;
- CSV/XLSX import s povinným náhledem před uložením.

Výchozí složky používají české kódy bez diakritiky, aby byly bezpečné i pro
CSV a jiné strojové zpracování. Patří mezi ně například `MZDA_MESICNI`,
`MZDA_HODINOVA`, `ODMENA`, `NAHRADA_MZDY`, `NEPENEZNI_PRIJEM`,
`PRISPEVEK_STRAVOVANI` a `CESTOVNI_NAHRADA`. Stejné kódy používej také ve
sloupci `component_code` importovaného souboru. U nové vlastní složky zadej
nejprve název; kód se z něj automaticky vytvoří bez diakritiky. Dokud jej ručně
neupravíš, sleduje změny názvu. Po uložení už kód ani začátek platnosti změnit
nelze; další účinnost se zakládá jako nová verze.

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

## Povinné spoření u rizikové práce

Panel **Povinné spoření u rizikové práce** slouží pouze pro práce 3. kategorie,
u nichž je rozhodným faktorem vibrace, chlad, teplo nebo dynamická fyzická
zátěž velkými svalovými skupinami. Nestačí obecné označení vztahu jako
rizikového. U každého dotčeného vztahu proto vyberte konkrétní zákonný faktor
a za měsíc zadejte počet rozhodných osmin směny. Celá osmihodinová směna má
osm osmin; u směny jiné délky se každá započatá hodina počítá jako jedna
osmina. Povinnost vzniká od 24 osmin za měsíc.

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
auditní historii. Výpočet použije 4 % z nezastropovaného vyměřovacího základu,
výsledek zaokrouhlí nahoru na celé koruny a stanoví splatnost na poslední den
následujícího měsíce. Uhrazení potvrďte až podle skutečného bankovního pohybu.
Po schválení mzdové revize aplikace vytvoří samostatný závazek **Povinné
spoření u rizikové práce** v Mzdových příkazech. Odtud jej zařaďte do ABO
nebo SEPA dávky stejně jako ostatní mzdové odvody. Za uhrazený se považuje až
po spárování bankovní transakce nebo pokladního dokladu; v panelu podkladů se
stav ručně nepřepíná.
