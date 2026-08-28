# Rychlý měsíční vstup

## 62.1 Účel

Rychlý měsíční vstup umožňuje zadat jednorázové částky a jednotky pro více zaměstnanců v jednom období bez otevírání každého vztahu zvlášť.

## 62.2 Předpoklady a oprávnění

Musí být vybraný správný měsíc, existovat aktivní vztahy a nastavené mzdové složky. Uživatel potřebuje mzdové oprávnění a podklad pro každý údaj.

## 62.3 Krokový postup

1. Otevřete **Mzdy → Rychlý měsíční vstup** a ověřte firmu a období.
2. Projděte zaměstnance a doplňte základ, přesčas a odměnu tam, kde se něco mění.
3. Zadejte částku nebo počet jednotek v očekávaném formátu.
4. Jedním tlačítkem uložte celou sadu; nemusíte ukládat po stránkách.
5. Porovnejte souhrn se zdrojovým podkladem a přepočítejte otevřený běh.

## 62.4 Stavy

Uložený vstup čeká na zpracování během; podle oprávnění uživatele vznikne rovnou
jako schválený, nebo jako koncept ke schválení. V otevřeném běhu se projeví po výpočtu. Po uzavření nelze změnou vstupu přepsat archivovaný výsledek; je nutný podporovaný opravný postup.

## 62.5 Kontroly a bezpečnost

Kontrolujte období, souběžný vztah, znaménko, jednotku a duplicity. Hromadné zadání zvyšuje riziko záměny osoby; před uložením používejte kontrolní součet. Zdrojovou přílohu nesdílejte mimo oprávněný okruh.

## 62.6 Časté chyby

- Vstup v jiném měsíci nebo u jiného vztahu.
- Duplicitní ruční zadání již importované částky.
- Jednorázová částka vložená do pravidelné složky.
- Oprava vstupu bez nového výpočtu.

## 62.7 Návaznosti

Význam složek popisuje [kapitola 58p](74_Mzdove_slozky_a_vstupy.md). Výsledek ověřte v [mzdovém běhu](63_Mzdove_behy.md) a následně v [platbách](65_Platby_a_uhrady.md).



## 62.8 Podrobný pracovní postup a kontroly

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
když má vztah pro dané čtvrtletí schválený průměrný hodinový výdělek. Bez
schváleného podkladu aplikace hodinovou sazbu neodhaduje a vyžádá celkovou
částku. U závislého příjmu společníka, odměny za výkon funkce, DPP a DPČ se
hodinový přesčas nenabízí; použije se doložená celková částka nebo odměna.

Přesčas zadaný hodinami se **rozdělí na dvě části**, protože jsou to dva různé
nároky:

- **dosažená mzda** za odpracované přesčasové hodiny — složka `MZDA_HODINOVA`.
  Počítá se z měsíčního základu děleného **fondem hodin daného měsíce**, ne
  paušální sazbou;
- **příplatek za práci přesčas** podle § 114 — složka `PRIPLATEK_PRESCAS`.
  Počítá se z doloženého průměrného výdělku sazbou z legislativní sady, takže
  se propíše i sazba sjednaná výš než zákonné minimum.

Rozdělení není kosmetika: z mzdového listu musí být vidět, čím byl nárok na
příplatek uspokojen (§ 142 odst. 5 zákoníku práce). Dřív obojí splývalo do
jedné sběrné složky a základ se počítal jinak.

Hodinový režim vyžaduje, aby měl vztah pro daný měsíc **přiřazený pracovní
kalendář** — bez něj není z čeho určit fond hodin a aplikace vyžádá celkovou
částku. Je-li mzda sjednána s přihlédnutím k práci přesčas (§ 114 odst. 3),
přesčas hodinami zadat nelze, protože příplatek ani náhradní volno nepřísluší.
Je-li u vztahu sjednáno **náhradní volno** místo příplatku, uloží se jen
dosažená mzda a příplatková část je nulová.

**Přesčas zadejte buď tady, nebo v docházce, nikdy obojím.** Máte-li přesčas
v rychlém vstupu, schválení měsíce docházky s přesčasovými hodinami se zastaví
a naopak; jeden přesčas nelze vykázat dvakrát.

Ostatní zákonné příplatky — noční práce, víkend, svátek a ztížené prostředí —
se tady zadat nedají. Vznikají výhradně z
[docházky](60_Dochazka_a_smeny.md#6093-zakonne-priplatky-ke-mzde-114-az-118).

Hromadné uložení vytváří běžné vstupy složek `MZDA_MESICNI`,
`PREMIE_PRIPLATKY` a `ODMENA`, takže nevzniká paralelní evidence mezd.
Opakované uložení stejného měsíce nevytvoří duplicity.

**Uložení a schválení je jeden krok.** Má-li přihlášený uživatel právo mzdové
vstupy schvalovat, uloží se rozepsané řádky rovnou jako schválené a mzdový běh
je bez dalšího zásahu přebere. Uživatel bez tohoto práva ukládá koncepty, které
někdo se schvalovacím oprávněním potvrdí později — buď po jednom, nebo hromadně
tlačítkem **Schválit vše (N)**. Ani u pěti set zaměstnanců tedy nemusíte
schvalovat řádek po řádku.

Ukládá se **celá rozepsaná sada, ne jen zobrazená stránka.** Rozepsané změny
přežijí přechod na další stránku a při uložení se pošlou i řádky z ostatních
stránek. Uložení se posílá po dávkách, takže funguje i pro stovky zaměstnanců.

Selhání jednoho řádku už neshodí uložení celé stránky. Chybný řádek se vrátí
zpět s červeným označením **konkrétního pole** a s důvodem přímo u něj;
ostatní řádky se uloží. Opravte označená pole a uložení zopakujte.

Rozpracované vstupy se mění s kontrolou jejich verze; už zpracovaný nebo
uzamčený vstup formulář nikdy nepřepíše. Pokud základní mzdu už spravuje pravidelný či jiný měsíční vstup,
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
