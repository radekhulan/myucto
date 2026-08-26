# Dohody o srážkách

## Účel

Agenda eviduje dobrovolné nebo smluvní srážky zaměstnance, jejich pořadí, limity, příjemce a časovou platnost odděleně od výkonu rozhodnutí.

## Předpoklady a oprávnění

Musí existovat zaměstnanec a platný právní podklad. Uživatel potřebuje mzdové oprávnění; k platebním údajům a dokumentům dohody má mít přístup jen pověřená osoba.

## Krokový postup

1. Otevřete **Mzdy → Dohody o srážkách** a vyberte zaměstnance i vztah.
2. Zadejte typ, účinnost, částku nebo pravidlo, příjemce a platební údaje.
3. Ověřte pořadí vůči jiným srážkám a zákonné omezení disponibilní mzdy.
4. V prvním dotčeném běhu zkontrolujte výpočet a závazek příjemci.
5. Při změně či ukončení založte časově navazující stav, nepřepisujte minulost.

## Stavy

Dohoda může být budoucí, aktivní, pozastavená, ukončená nebo plně vypořádaná. Stav evidence není důkazem souhlasu zaměstnance; ten vyplývá z externího právního podkladu.

## Kontroly a bezpečnost

Zkontrolujte oprávněnost, datum, maximální částku, pořadí a účet příjemce. Dokument dohody uchovávejte bezpečně. Volitelný odkaz na dokument nenahrazuje vyplnění účinnosti a pravidla srážky.

## Časté chyby

- Smluvní dohoda zařazená jako exekuce nebo naopak.
- Srážka pokračující po dosažení limitu.
- Neplatný účet příjemce.
- Ruční částka zadaná současně s aktivní automatickou dohodou.

## Návaznosti

Nucené srážky řeší [kapitola 58m](71_Srazky_a_exekuce.md), výpočet [mzdové běhy](63_Mzdove_behy.md) a následnou úhradu [platby](65_Platby_a_uhrady.md).



## Podrobný pracovní postup a kontroly

Dobrovolné a standardní srážky — zálohy, stravování, spoření, náhrada škody
a příspěvky — spravuješ v **Mzdy → Dohody o srážkách**. Dohoda má titul,
příjemce, druh, pořadí, částku a účinnost od–do; volitelně i celkový limit,
po jehož vyčerpání se srážka přestane uplatňovat. Částku lze zadat pevně,
nebo procentem ze zadaného základu — z procenta a základu se uloží pevná
částka, protože mzdový běh zmrazuje podklady dřív, než zná výsledný příjem.

Pořadí zadáváš v rozsahu 10–9999. Nižší pásmo je vyhrazené zákonným
a exekučním srážkám, takže dobrovolná dohoda nikdy nepředběhne přednostní
pohledávku ani neobejde nezabavitelnou částku — výpočet dobrovolné srážky vždy
omezí volnou kapacitou po zákonných srážkách.

Dohoda prochází stavy **Návrh → Aktivní → Pozastavená → Ukončená**; návrh, který
ještě nemá jediný pohyb, lze zrušit. Do mzdového běhu vstupuje jen aktivní
dohoda účinná v daném období. Změna dohody nikdy nepřepíše podklady už schválené
mzdy: uloží se jako nová účinná verze a historie verzí i pohybů zůstává v detailu
dohody. Ukončení dohodu zastaví, ale historii sražených částek nemaže. Pokud
dohodu mezitím změnil někdo jiný nebo do ní přibyl pohyb, uložení skončí
konfliktem a formulář se načte znovu — poslední zápis nikdy tiše nevyhrává.
