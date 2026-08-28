# Absence a dovolená

## 59.1 Účel

Agenda eviduje dovolenou, překážky, neplacené volno a dočasnou pracovní neschopnost (DPN), aby se správně promítly do odpracované doby, náhrad a mzdy.

## 59.2 Předpoklady a oprávnění

Musí existovat zaměstnanec, aktivní pracovní vztah a pracovní kalendář. Uživatel potřebuje mzdové oprávnění a ověřený podklad s typem, intervalem a případným rozsahem hodin.

## 59.3 Krokový postup

1. Otevřete **Mzdy → Absence a dovolená** a vyberte období, zaměstnance a vztah.
2. Zvolte typ absence, zadejte začátek, konec a požadované doplňující údaje.
3. U dovolené porovnejte požadavek s nárokem a rozvrhem směn.
4. U DPN ověřte identifikátor a průběžně zapisujte změny i ukončení podle doložených údajů.
5. Před mzdovým během odstraňte překryvy a porovnejte absenci s docházkou.

## 59.4 Stavy

Událost může být budoucí, probíhající nebo ukončená. Otevřená DPN čeká na doplnění konce. Zařazení do mzdy se řídí daty a kalendářem, nikoli dnem vložení záznamu.

## 59.5 Kontroly a bezpečnost

Zdravotní údaje zpřístupněte jen oprávněným osobám a neevidujte diagnózu, pokud není potřebná. Kontrolujte překryvy, směny, svátky, čerpání nároku a návaznost DPN. Odkaz na podklad je volitelná auditní stopa; interval a typ absence jsou skutečné vstupy.

## 59.6 Časté chyby

- Záměna kalendářních dnů za pracovní dny nebo hodiny.
- Dvojí zadání stejné nepřítomnosti v docházce i absencích.
- DPN bez ukončení.
- Změna události po uzavření mzdy bez řízené opravy.

## 59.7 Návaznosti

Absence se porovnávají s [docházkou a směnami](60_Dochazka_a_smeny.md) a vstupují do [mzdového běhu](63_Mzdove_behy.md). Případné výstupy a hlášení popisují [kapitoly 58h](66_Dokumenty_a_vystupy.md) a [58j](68_Podani_a_hlaseni.md).



## 59.8 Podrobný pracovní postup a kontroly

V **Mzdy → Absence a dovolená** jsou tři navazující agendy:

- absence se schvalovacím stavem a kontrolou překryvu;
- čtvrtletní snapshot průměrného nebo pravděpodobného hodinového výdělku;
- hodinový ledger dovolené, ve kterém oprava vytváří novou položku a nemaže historii.

Nejprve vyber pracovní vztah a založ snapshot průměrného výdělku. Skutečný
průměr používá započitatelnou mzdu a odpracovaný čas v rozhodném období.
Formuláře zadávají částky v Kč a čas v hodinách či dnech; interní haléře
a minuty převádí aplikace až při uložení. Stejně se v hodinách zadává částečný
první nebo poslední den absence i ruční změna dovolené. Přesný důvod chyby
zůstane viditelný přímo u příslušného formuláře.
Při méně než 21 odpracovaných dnech je povinný pravděpodobný hodinový výdělek
a jeho odůvodnění. Snapshot musí projít ruční kontrolou a schválením; teprve
potom jej lze připojit k absenci s náhradou.

Nárok dovolené se vede v minutách. U DPP a DPČ výpočet používá zákonnou
fiktivní týdenní pracovní dobu 20 hodin. Započitatelné a náhradní doby,
změny úvazku, krácení a další právní okolnosti před uložením vždy ověř.
Schválení čerpání zapíše zápornou položku podle publikovaných směn. Zrušení
schváleného čerpání ji nemaže, ale vytvoří kladnou reverzi a označí absenci
pro kontrolu případné opravy mzdy.

### 59.8.1 Hromadný výpočet nároku

Firemní výměru nastav v **Mzdy → Nastavení → Politiky a připravenost**. Musí
mít nejméně 4 týdny; běžnou hodnotou je 5 týdnů. Jen odlišný pracovní vztah
má na své kartě výjimku. Pokud ji nevyplníš, automat převezme účinnou firemní
výměru. Odkaz na zdroj je volitelná auditní poznámka a výpočet ho nevyžaduje.

Na záložce **Dovolená** se pracovní vztahy načítají po stránkách, takže stejný
postup funguje pro deset i stovky zaměstnanců. Aplikace pro každý vztah spojí
účinné smluvní podmínky, sjednanou týdenní pracovní dobu, firemní politiku a
schválené měsíce docházky. Vyber připravené vztahy na aktuální stránce a spusť
výpočet; bez tohoto kroku se nic nezapisuje. Jedna mzdová účetní může výběr i
zápis dokončit sama.

Výpočet se bezpečně zastaví jen tehdy, když chybí skutečný právní údaj, změnila
se během roku výměra nebo pracovní doba, jiná absence vyžaduje právní posouzení
započitatelnosti, případně chybí schválený měsíc docházky. Již existující nárok
automat nepřepíše. Při uložení znovu ověří otisk podkladů; mezitím změněná data
vyžadují obnovení přehledu. Uložená revize uchová použitou politiku, smluvní
podmínky, schválenou docházku i výpočetní stopu.

Náhrada při DPN se počítá pouze z publikovaných směn v prvních 14 kalendářních
dnech. Před schválením potvrď účast na nemocenském pojištění a vyloučení
souběžné dávky. Pokud zaměstnanec první plánovanou směnu celou odpracoval,
označ tuto skutečnost; čtrnáctidenní okno pak začíná následujícím dnem.
Výsledek uchovává použitý průměr, redukční hranice, pravidla, zaokrouhlení
a rozpad po směnách. Diagnóza se v agendě absence neeviduje.

Při schválení měsíce v **Mzdy → Docházka a směny** se samostatně
potvrzuje pracovní jádro JMHZ: stanovený a sjednaný měsíční fond, stanovená
týdenní doba, evidenční dny a skutečně odpracované hodiny. Nabídnuté hodnoty
jsou pouze dohledatelný podklad; před schválením je potvrď jako přesná desetinná
čísla. Poznámku k ověření můžeš nechat prázdnou. Aplikace zde potichu
nezaokrouhluje minuty ani nedopočítá
chybějící profesní fond. Potvrzený souhrn je neměnný a navázaný na konkrétní
revizi schváleného měsíce; po znovuotevření je nutné vytvořit nové potvrzení.

Součástí potvrzení jsou také dvě povinná rozhodnutí **Ano/Ne**: zda v měsíci
nastaly neodpracované hodiny (IN07) a zda nastaly překážky v práci (IN08).
Systém žádnou z odpovědí nepředvyplní jako **Ne**. Při IN07 se uvádí celkový
rozsah a případně placené hodiny, DPN s náhradou nebo bez ní, dovolená a péče.
Při IN08 musí být uvedena alespoň jedna hodnota překážek na straně zaměstnance
nebo zaměstnavatele. Jednotlivé kategorie se mohou překrývat, proto se jejich
součet nesmí automaticky rovnat celkovým neodpracovaným hodinám. Evidence
absencí slouží jako podklad k ruční kontrole, nikoli jako automatická právní
klasifikace. Nevyřízená absence nebo čekající oprava schválení měsíce blokuje.
Schválená placená dovolená může projít běžným profilem JMHZ jen tehdy, když
souhlasí s publikovanými směnami a potvrzený pracovní souhrn ji vykazuje celou
jako placené neodpracované hodiny. Neplacené volno a ostatní nestandardní
absence zůstávají bez doložených údajů pro ELDP bezpečně zablokované.

> [!WARNING]
> Agenda je označena **Vyžaduje ruční kontrolu**. Bez schváleného průměru,
> publikovaného rozvrhu nebo potvrzených zákonných podmínek výpočet bezpečně
> selže; systém chybějící údaj neodhaduje. Výpočty náhrad a dovolené jsou
> aktuálně dostupné pouze pro legislativní ruleset roku 2026.
