# Rychlý měsíční vstup

## Účel

Rychlý měsíční vstup umožňuje zadat jednorázové částky a jednotky pro více zaměstnanců v jednom období bez otevírání každého vztahu zvlášť.

## Předpoklady a oprávnění

Musí být vybraný správný měsíc, existovat aktivní vztahy a nastavené mzdové složky. Uživatel potřebuje mzdové oprávnění a podklad pro každý údaj.

## Krokový postup

1. Otevřete **Mzdy → Rychlý měsíční vstup** a ověřte firmu a období.
2. Vyberte zaměstnance, vztah a složku.
3. Zadejte částku nebo počet jednotek v očekávaném formátu.
4. Uložte řádek a pokračujte dalšími zaměstnanci.
5. Porovnejte souhrn se zdrojovým podkladem a přepočítejte otevřený běh.

## Stavy

Uložený vstup čeká na zpracování během. V otevřeném běhu se projeví po výpočtu. Po uzavření nelze změnou vstupu přepsat archivovaný výsledek; je nutný podporovaný opravný postup.

## Kontroly a bezpečnost

Kontrolujte období, souběžný vztah, znaménko, jednotku a duplicity. Hromadné zadání zvyšuje riziko záměny osoby; před uložením používejte kontrolní součet. Zdrojovou přílohu nesdílejte mimo oprávněný okruh.

## Časté chyby

- Vstup v jiném měsíci nebo u jiného vztahu.
- Duplicitní ruční zadání již importované částky.
- Jednorázová částka vložená do pravidelné složky.
- Oprava vstupu bez nového výpočtu.

## Návaznosti

Význam složek popisuje [kapitola 58p](58p_Mzdove_slozky_a_vstupy.md). Výsledek ověřte v [mzdovém běhu](58e_Mzdove_behy.md) a následně v [platbách](58g_Platby_a_uhrady.md).



## Podrobný pracovní postup a kontroly

V **Mzdy → Rychlý měsíční vstup** vybereš měsíc a upravíš všechny účinné
pracovní vztahy na jedné stránce. U každého zaměstnance se zobrazí jméno,
maskované rodné číslo, typ vztahu, základní mzda nebo odměna ze vztahu,
přesčas a bonus či další odměna. Pracovní poměr, DPP, DPČ, závislý příjem
společníka a odměna za výkon funkce zůstávají v samostatných řádcích a systém
je neslučuje.
Náhled hrubé mzdy se přepočítává okamžitě; další již existující mzdové vstupy
jsou v něm zobrazeny samostatně. Do hrubého náhledu vstupují všechny složky
zařazené jako zdanitelný příjem včetně nepeněžních. Osvobozené náhrady a jiné
složky mimo hrubý příjem se zobrazí zvlášť a do součtu se nepřičtou. Složka
s neuzavřeným daňovým zařazením vytvoří ruční kontrolu. Jde pouze o náhled
hrubých složek, nikoli o výpočet čisté mzdy; ten vznikne až ve mzdovém běhu.

Přesčas lze zadat celkovou částkou. Zadání v hodinách je dostupné pouze tehdy,
když má vztah pro dané čtvrtletí schválený průměrný hodinový výdělek. Systém
pak použije tento doložený průměr a 25% příplatek; bez schváleného podkladu
hodinovou sazbu neodhaduje a vyžádá celkovou částku. U závislého příjmu
společníka, odměny za výkon funkce, DPP a DPČ se hodinový přesčas s 25%
příplatkem nenabízí; použije se doložená celková částka nebo odměna.

Hromadné uložení vytváří běžné vstupy složek `MZDA_MESICNI`,
`PREMIE_PRIPLATKY` a `ODMENA`, takže nevzniká paralelní evidence mezd.
Opakované uložení stejného měsíce nevytvoří duplicity. Rozpracované vstupy se
mění s kontrolou jejich verze; schválený nebo uzamčený vstup formulář nikdy
nepřepíše. Pokud základní mzdu už spravuje pravidelný či jiný měsíční vstup,
rychlý formulář ji zobrazí pouze pro čtení. Kontroluje také verzi pracovního
vztahu, takže po souběžné změně smlouvy vyžádá obnovení formuláře. Historický
měsíc zachová vztah, který byl tehdy účinný a později archivován. Při nástupu,
ukončení nebo pozastavení v průběhu měsíce nepředvyplní plnou měsíční mzdu a
vyžádá skutečnou částku za zpracovávané období. Plný měsíční pravidelný předpis
v takovém měsíci také nepřevezme automaticky; zůstane v ruční kontrole, dokud
není doložené správné časové rozpočítání.

Částky zadávej v Kč s nejvýše dvěma desetinnými místy a hodiny s nejvýše
třemi. Prázdná hodnota neznamená nulu; pokud složka v měsíci není, zadej
**0**. Formulář označí konkrétní chybné pole a rozliší chybějící hodnotu,
neplatný formát, záporné číslo a překročení podporovaného rozsahu. Pokud se
uložení nepodaří například proto, že stejný vstup mezitím změnil jiný
uživatel, přesný důvod zůstane viditelný nad formulářem. Před dalším pokusem
načti aktuální měsíc tlačítkem **Obnovit** a změny zkontroluj.
