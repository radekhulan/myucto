# 78. Cestovní náhrady

> Návod, jak zapsat tuzemskou pracovní cestu, spočítat náhrady podle
> vyhlášky, vypořádat zálohu a promítnout vyúčtování do mzdy. Pro mzdové
> účetní a každého, kdo vyúčtovává cesty zaměstnanců.

## 78.1 Kdy to potřebujete

- Zaměstnanec se vrátil z pracovní cesty a předložil doklady.
- Zaměstnanec dostal na cestu zálohu a je potřeba ji vypořádat.
- Panel **Zákonné termíny** upozorňuje na lhůtu předložení dokladů nebo
  vyúčtování cesty.

<!-- cols: 30 40 30 -->
| Kdy | Co udělat | Kde v aplikaci |
|---|---|---|
| do 10 pracovních dnů po skončení cesty | Zaměstnanec předloží doklady, vy zapíšete den předložení | `Mzdy → Cestovní náhrady`, pole **Doklady předloženy dne** |
| do 10 pracovních dnů od předložení | Cestu vyúčtovat (schválit) | tlačítko **Schválit vyúčtování** |
| před mzdovým během | Promítnout vyúčtování do mzdy | tlačítko **Promítnout do mzdy** |

## 78.2 Než začnete

1. **Zaměstnanec a pracovní vztah** založené v `Mzdy → Zaměstnanci`.
2. **Podklady k cestě:** odjezd a návrat s časem, místo, účel, dopravní
   prostředek, poskytnutá záloha, doklady k výdajům a poskytnutá bezplatná
   jídla.
3. **Oprávnění:** zakládat a upravovat cesty smí role s oprávněním pro
   mzdové vstupy. Schválení a promítnutí do mzdy vyžaduje oprávnění pro
   schvalování. Bez oprávnění ke změnám cesty jen prohlížíte.
4. **Účty pro účtování** (jen v podvojném účetnictví) zkontrolujte
   v [Nastavení mezd](90_Nastaveni_mezd.md#90146-predkontace-pro-zvlastni-mzdove-situace).

## 78.3 Krok za krokem: vyúčtování pracovní cesty

1. Otevřete `Mzdy → Cestovní náhrady` a nahoře zvolte **Období vyúčtování**.
2. Klikněte na **Nová pracovní cesta**.
3. Vyberte **Zaměstnanec** a **Pracovní vztah**.
4. Vyplňte **Odjezd** a **Návrat** v místním čase, **Dopravní prostředek**,
   **Místo odjezdu**, **Místo výkonu práce** a **Účel cesty**.
5. Zkontrolujte sazby stravného pro jednotlivá pásma. U každého pole vidíte
   zákonné minimum.
6. Vyplňte **Poskytnutá záloha (Kč)** a zvolte **Vypořádání zálohy**:
   **Mzdou** nebo **Pokladnou** (viz [§ 78.5.4](#7854-vyporadani-zalohy)).
7. Zapište **Doklady předloženy dne**.
8. V části **Doložené výdaje** klikněte na **Přidat položku** a zapište
   jízdné, ubytování, nutné vedlejší výdaje nebo jízdu soukromým vozidlem
   (ujeté kilometry, spotřeba na 100 km, palivo, případně doložená cena PHM).
9. V části **Bezplatná jídla** klikněte na **Přidat den** a zadejte počet
   poskytnutých jídel.
10. Klikněte na **Přepočítat náhled** a zkontrolujte rozpad po dnech
    a položkách, část **Do limitu**, **Nadlimitní** část a vypořádání zálohy.
11. Klikněte na **Uložit**.
12. V seznamu klikněte u cesty na **Schválit vyúčtování**.
13. Klikněte na **Promítnout do mzdy**. Při vypořádání pokladnou vyplaťte
    doplatek nebo přijměte vrácený přeplatek pokladním dokladem; částku
    aplikace ohlásí po promítnutí.

**Jak poznáte, že je hotovo:** Cesta má stav **Promítnutá do mzdy**
a v mzdovém běhu za období vyúčtování jsou její mzdové vstupy. Zpráva po
promítnutí uvádí, kolik vstupů vzniklo. Panel **Zákonné termíny** už lhůtu
cesty neukazuje.

> [!TIP]
> Opakovaný požadavek na promítnutí nevytvoří duplicitní mzdové vstupy.
> Nejde však o způsob opravy již schváleného vyúčtování.

## 78.4 Když něco nejde

<!-- cols: 32 32 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| *„Vyúčtování vyžaduje ruční posouzení a nelze ho schválit“* | Sazba stravného je nižší než zákonné minimum, chybí účinná sazba, nebo jde o zahraniční cestu | Opravte sazbu, nebo cestu vyúčtujte mimo aplikaci. |
| *„Zadaný čas odjezdu nebo příjezdu v této časové zóně neexistuje“* | Zvolená hodina při přechodu na letní čas neexistuje | Zvolte jiný čas. |
| Do mzdy šel celý nárok, i když zaměstnanec dostal zálohu z pokladny | Záloha nebyla správně zapsaná před schválením | Ověřte vyúčtování a zálohu. Schválenou cestu nelze přepsat a opakované promítnutí chybějící odečet nepřidá; rozdíl vypořádejte opravnou revizí mzdy a doložte účetně. |
| *„Záloha převýšila nárok. Zaměstnanec vrací do pokladny …“* | Záloha byla vyšší než nárok | Přeplatek se ze mzdy nesráží; přijměte ho pokladním dokladem. |
| *„Nezdaněnou část se nepodařilo zaúčtovat …“* | Účetní období je zavřené | Prověřte vzniklé mzdové vstupy i deník a opravte účtování v přípustném období. Stav Promítnutá do mzdy již nenabízí nové promítnutí z webového seznamu. |
| *„Nemáte oprávnění měnit pracovní cesty“* | Chybí oprávnění pro mzdové vstupy | Požádejte správce o oprávnění. |
| Účtenka je ve vyúčtování dvakrát | Duplicitně vložená položka | Smažte ji u položky tlačítkem **Smazat**. |
| Stravné je vyšší, než má být | Chybí zápis poskytnutých jídel | Doplňte **Bezplatná jídla**. |

## 78.5 Podrobnosti a pravidla

### 78.5.1 Výpočet náhrad

Agenda vede tuzemské pracovní cesty. Čas odjezdu a návratu zadáváte místní;
systém k němu uloží časovou zónu, takže cesta a rozvržená směna na sebe
sedí i v období změny letního času. Hodinu, která se na jaře přeskakuje,
formulář nepřijme.

Nárok se počítá z účinné vyhlášky k rozhodnému dni:

- stravné podle časových pásem cesty (5 až 12 h, nad 12 do 18 h, nad 18 h)
  za každý kalendářní den; u cesty spadající do dvou kalendářních dnů se
  použije výhodnější varianta;
- krácení stravného za každé poskytnuté bezplatné jídlo;
- základní náhrada za ujetý kilometr a náhrada za spotřebované pohonné
  hmoty z průměrné ceny podle vyhlášky, nebo z doložené ceny;
- doložené ubytování a nutné vedlejší výdaje.

Náhled ukazuje rozpad po dnech i po položkách a rozdělí výsledek na část
**do zákonného limitu**, která není předmětem daně, pojistného ani
exekučních srážek, a na **nadlimitní část**, která do mzdy vstupuje jako
zdanitelný příjem a do vyměřovacích základů. Sazba stravného nižší než
zákonné minimum, chybějící účinná sazba i zahraniční pracovní cesta skončí
v ruční kontrole a vyúčtování nejde schválit.

Cestovní náhradu nepoužívejte jako obecnou nezdaněnou mzdovou složku.
Schvalujte jen cestu s vazbou na skutečný pracovní vztah a účel. Přílohy
mohou obsahovat osobní údaje, ukládejte je bezpečně.

### 78.5.2 Stavy cesty

Cesta je **Rozpracovaná**, **Schválená**, **Promítnutá do mzdy** nebo
**Zrušená**. Rozpracovaný výpočet není účetním dokladem ani příkazem
k úhradě. **Upravovat lze jen rozpracovanou cestu.** Schválením se cesta
vyúčtuje a její podklady se již nepřepisují; schválená cesta už termín nemá.

### 78.5.3 Promítnutí do mzdy a lhůty

**Promítnout do mzdy** založí mzdové vstupy na složkách cestovní náhrady do
limitu a nadlimitní cestovní náhrady (`CESTOVNI_NAHRADA_LIMIT`,
`CESTOVNI_NAHRADA_NADLIMIT`) v období vyúčtování. Opakované promítnutí
nevytvoří duplicitu.

Zaměstnanec předloží doklady do **10 pracovních dnů** po skončení cesty
a zaměstnavatel cestu vyúčtuje do **10 pracovních dnů** od jejich předložení
(§ 183 odst. 1 zákoníku práce). Rozpracovaná cesta v seznamu ukazuje, do
kdy doklady čekají (**Doklady do**), a po jejich předložení, do kdy ji
vyúčtovat (**Vyúčtovat do**). Obě lhůty hlídá i panel **Zákonné termíny**;
proklik otevře editor cesty ve správném měsíci.

### 78.5.4 Vypořádání zálohy

Vyúčtování cesty je nárok minus poskytnutá záloha (§ 183 zákoníku práce).
Kde se rozdíl vyrovná, určuje u cesty volba **Vypořádání zálohy**:

- **Mzdou (záloha se odečte ve výplatě):** do mzdy jde celý nárok (obě
  části se zdaní nebo nezdaní podle zákona) a záloha se z výplaty odečte
  složkou zálohy na cestovní náhrady (`CESTOVNI_NAHRADA_ZALOHA`). Odečte se
  nejvýš celý nárok. Převýšila-li záloha nárok, přeplatek se ze mzdy
  nesráží, zaměstnanec ho vrací do pokladny a aplikace ho po promítnutí
  ohlásí.
- **Pokladnou (do mzdy jen nadlimitní část):** nezdaněná část se do mzdy
  nepošle a doplatek nebo vrácení přeplatku vyrovnáte pokladním dokladem.
  Do mzdy jde jen nadlimitní část, protože se musí zdanit a započítat do
  vyměřovacích základů.

Náhled ukazuje zálohu, dopad do výplaty, doplatek z pokladny a přeplatek,
který zaměstnanec vrací, přesně tak, jak je promítnutí založí.

### 78.5.5 Účtování v podvojném účetnictví

Náhrada se účtuje na nákladový účet cestovného (výchozí 512) proti
závazkovému účtu pracovního vztahu, tedy proti témuž účtu, ze kterého se
vyplácí mzda. Zálohu vyplacenou z pokladny účtujte na pohledávku za
zaměstnancem (335):

- při vypořádání **mzdou** ji odečet ve výplatě vyrovná zápisem MD 331 /
  D 335,
- při vypořádání **pokladnou** zaúčtuje vyúčtování nezdaněnou část MD 512 /
  D 335 a pokladní doklad doplatku (MD 335 / D 211) nebo vrácení přeplatku
  (MD 211 / D 335) pohledávku vyrovná.

Účty lze změnit v
[Nastavení mezd](90_Nastaveni_mezd.md#90146-predkontace-pro-zvlastni-mzdove-situace).

## 78.6 Související kapitoly

- [Rychlý měsíční vstup](79_Rychly_mesicni_vstup.md) a [Mzdové běhy](80_Mzdove_behy.md): kontrola mzdového dopadu.
- [Mzdové příkazy a úhrady](82_Platby_a_uhrady.md): úhrada výplaty.
- [Nastavení mezd](90_Nastaveni_mezd.md): účty pro cestovní náhrady.