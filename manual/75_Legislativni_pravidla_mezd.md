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
