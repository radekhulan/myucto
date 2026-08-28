# Legislativní pravidla mezd

## 75.1 Účel

Legislativní pravidla poskytují datované parametry pro výpočet: sazby, limity, redukční hranice, minimální hodnoty, daňové slevy a parametry srážek.

## 75.2 Předpoklady a oprávnění

Pro prohlížení je nutné mzdové oprávnění, pro správu `payroll.rulesets`. Změnu smí provést jen pověřená osoba na základě ověřeného právního zdroje a s přesným datem účinnosti.

## 75.3 Krokový postup

1. Otevřete **Mzdy → Legislativní pravidla mezd** a vyhledejte rozhodné období.
2. Zkontrolujte, že existuje právě jedna použitelná sada s odpovídající účinností.
3. Porovnejte sazby a limity s oficiálním zdrojem; do evidence zdroje lze vložit bezpečný veřejný odkaz.
4. Novou hodnotu založte jako datovanou verzi, nepřepisujte pravidlo použité v uzavřeném období.
5. Před ostrým použitím proveďte kontrolní výpočet hraničních a běžných případů.

## 75.4 Stavy

Pravidlo může být budoucí, účinné nebo historické. Chybějící či překrývající se účinnost musí výpočet zastavit nebo vyvolat kontrolu. Aktivní neznamená automaticky právně ověřené; rozhoduje zdroj a schválení.

## 75.5 Kontroly a bezpečnost

Jedna oprávněná účetní musí zvládnout pravidlo založit, ověřit i použít bez povinného schválení druhou osobou. Aplikace přesto uchovává auditní stopu hodnoty, účinnosti a změn. Odkaz na zdroj je volitelná důkazní informace, nikoli podmínka výpočtu. Nikdy do něj nevkládejte interní přihlašovací údaje. Změna pravidla může ovlivnit mzdy, platby, účetnictví i podání.

## 75.6 Časté chyby

- Použití současných hodnot pro starší období.
- Přepsání historické sady místo nové verze.
- Překryv dvou sad účinných ve stejný den.
- Kontrola jen běžného výpočtu bez limitních a souběžných případů.

## 75.7 Návaznosti

Obecné daňové hodnoty popisuje [kapitola 75](96_Danove_konstanty.md). Mzdová pravidla používají [běhy](63_Mzdove_behy.md), [roční zúčtování](67_Rocni_zuctovani.md) a [srážky](71_Srazky_a_exekuce.md).



## 75.8 Podrobný pracovní postup a kontroly

Sazby, hranice, lhůty a číselníky, ze kterých mzdový výpočet čerpá, jsou
rozdělené do samostatných oblastí (daň z příjmů, sociální a zdravotní pojištění,
hranice a minimální mzda, průměry a náhrady, cestovní náhrady, exekuční srážky,
termíny, číselníky a verze podání). Každá oblast má vlastní verze s obdobím
účinnosti. Otevřete je na **Mzdy → Legislativní pravidla mezd**.

Ověřená sada je součástí aplikace a používá se, dokud ji nikdo nezmění. Na téže
obrazovce ji lze přepsat, aniž by se čekalo na novou verzi programu — uloží se
jen změněné hodnoty, ostatní se dál berou z ověřené sady. Tlačítko **Vrátit
ověřenou hodnotu** ruční úpravu zahodí. Hodnoty jsou národní a společné pro
všechny firmy, takže je smí měnit jen superadmin; ostatní je vidí ke čtení.

Peníze se zadávají v korunách a sazby v procentech; převod na vnitřní jednotky
řeší aplikace.

Verze prochází stavy **Rozpracováno → Technicky zkontrolováno → Odborně
schváleno → Účinné → Nahrazeno**. Výpočet čerpá jen z účinné verze — dokud
verzi někdo neuvede do provozu, modul ji odmítne použít. Před schválením a
uvedením do provozu se kontroluje, že v období účinnosti dané oblasti nevzniká
mezera ani překryv a že uložené hodnoty odpovídají svému kontrolnímu součtu;
dokud kontrola neprojde, obrazovka u akce ukáže konkrétní důvod.

Ke každé verzi je vidět rozdíl proti ověřené sadě (co přibylo, co zmizelo a jak
se změnila hodnota) a historie změn: kdo, kdy, co a proč změnil. Historii nelze
mazat ani přepisovat. Pokud verzi schválí tentýž člověk, který ji upravil,
změna projde, ale obrazovka na to upozorní.

### 75.8.1 Které roky jsou pokryté

Ověřená sada je v aplikaci pro rok **2025** a **2026**. Rok 2025 je v ní proto,
aby šlo provést zpětnou opravu staršího měsíce a roční zúčtování za rok 2025
skutečnými pravidly toho roku, ne dnešními.

> [!IMPORTANT]
> **Exekuční srážky se v roce 2025 počítaly jinou formulí než v roce 2026.**
> V roce 2025 se z části základu srážela na povinného **dvě třetiny** a hranice
> plně zabavitelného zbytku byla **jedenapůlnásobek** základní částky. Od roku
> 2026 se poměr změnil na **85/100**, hranice na **1,9násobek** a k částce na
> bydlení přibyl samostatný paušál na energie. Opravujete-li rok 2025, vyjde
> jiné číslo než při stejném zadání v roce 2026 — a je to správně. Aplikace
> podíly bere z legislativní sady účinné pro dané období, takže je nepřepočítá
> dnešními pravidly.

Několik hodnot roku 2025 zůstává **nepotvrzených**, protože se dostupné zdroje
navzájem rozcházejí — jde o slevu pro pracujícího poplatníka v důchodu, slevu
u zemědělské dohody o provedení práce a zvláštní sazby pojistného
zaměstnavatele podle § 7 odst. 1 zákona č. 589/1992 Sb. (záchranné sbory,
riziková práce). Aplikace pro ně nedosadí odhad — výpočet, který je potřebuje,
se bezpečně zastaví a označí k ruční kontrole. Chcete-li je použít, doplňte je
v této agendě podle ověřeného právního zdroje. Běžné sazby sociálního
a zdravotního pojištění doložené jsou a počítají se normálně.

Rok 2025 záměrně neobsahuje termíny podání, číselníky a datové věty JMHZ ani
parametry povinného spoření u rizikové práce — obojí je účinné až od roku 2026.
JMHZ zavedl zákon č. 323/2025 Sb. až od 1. 1. 2026, takže se za rok 2025
nepodává a aplikace jeho přípravu za tento rok bezpečně odmítne. Rok 2025 je
v aplikaci pro **výpočet a opravu mezd**, ne pro podání.

### 75.8.2 Příští rok bez sady

Sada pro **rok 2027 zatím neexistuje a existovat nemůže**: odvíjí se od
průměrné mzdy, kterou vláda vyhlašuje nařízením až na podzim předchozího roku.
Nařízení musí vyjít **do 30. září**, takže od 1. října je sada sestavitelná
a je nejvyšší čas ji doplnit — bez ní mzdový modul od 1. ledna nespočítá ani
jednu výplatu.

Prázdná kostra sady se záměrně nezakládá. Rok s prázdnou sadou by se v přehledu
podporovaných období tvářil jako pokrytý, zatímco výpočet by na něm selhal.
Za pokrytý se počítá jen **účinná** sada; rozpracovaná verze rok nerozsvítí.

> [!IMPORTANT]
> Na chybějící sadu příštího roku vás obrazovka sama neupozorní. **Před prvním
> výpočtem nového roku si tedy v této agendě sami ověřte, že pro něj existuje
> účinná sada** — a to raději už na podzim, ne v lednu.

Doplnit sadu lze v této agendě bez zásahu do programu — hodnoty se zadávají
tak jako u kterékoli jiné verze. Tlačítko pro založení sady na nový rok zatím
v obrazovce chybí; do té doby o její založení požádejte provozovatele.
