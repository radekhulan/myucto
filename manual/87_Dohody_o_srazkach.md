# 87. Dohody o srážkách

> Návod, jak evidovat dobrovolné a zákonné srážky ze mzdy zaměstnance (zálohy,
> stravování, spoření, náhradu škody, příspěvky), jejich pořadí, limit
> a účinnost. Exekuce a insolvence jsou v kapitole
> [Srážky a exekuce](88_Srazky_a_exekuce.md).

## 87.1 Kdy to potřebujete

- Zaměstnanec podepsal dohodu o srážkách ze mzdy (obědy, splátka půjčky,
  náhrada škody, příspěvek).
- Zaměstnanci jste vyplatili zálohu na mzdu, kterou má vrátit, nebo mu vznikla
  nevyúčtovaná záloha či náhrada mzdy bez nároku (srážka ze zákona).
- Srážka se má změnit, na čas zastavit nebo skončit.
- Chcete zjistit, kolik už bylo sraženo a kolik zbývá do limitu.

## 87.2 Než začnete

1. **Zaměstnanec** musí být založený (viz [Zaměstnanci](86_Zamestnanci.md)).
2. **Právní podklad**: podepsaná dohoda, nebo doklad o záloze či náhradě mzdy.
   Evidence v aplikaci souhlas zaměstnance nedokládá, ten plyne z podkladu.
3. **Oprávnění**: k zápisu potřebujete oprávnění k mzdovým vstupům; s právem
   jen ke čtení dohodu neuložíte. K platebním údajům a dokumentům dohody má mít
   přístup jen pověřená osoba.

## 87.3 Krok za krokem: nová dohoda o srážce

1. Otevřete `Mzdy → Dohody o srážkách` a klikněte na **Nová dohoda**.
2. Vyberte **Zaměstnanec** a vyplňte **Titul dohody**. Pod tímto názvem se
   srážka objeví na výplatní pásce.
3. Zvolte **Právní titul srážky**:
   - **Dohoda o srážkách ze mzdy**: vyplňte **Doručeno plátci mzdy**,
   - srážka ze zákona (záloha k vrácení, nevyúčtovaná záloha, náhrada mzdy
     bez nároku): vyplňte **Den zahájení srážek**.
4. Zvolte **Druh srážky** a **Pořadí** (10 až 9999).
5. V **Zadání částky** zvolte **Pevná částka**, nebo **Procento ze základu**
   s **Základ pro procento (Kč)**. Volitelně vyplňte **Celkový limit (Kč)**.
   Při přechodu z jiného programu doplňte **Sraženo předchozím programem (Kč)**;
   jde o již provedené srážky, nikoli částku k nové výplatě.
6. Vyplňte **Účinnost od**, případně **Účinnost do**, a **Příjemce srážky**.
   U dohod o srážkách v sekci **Měsíční hlášení JMHZ (zaměstnanci OZP)**
   rozhodněte, zda je
   věřitelem zaměstnavatel nebo třetí osoba. U zaměstnavatele vyberte
   **Vykázat v JMHZ jako** (10350 až 10353). Význam a rozsah této evidence
   vysvětluje [§ 85.14.11](85_Podani_a_hlaseni.md#851411-zmrazeni-odeslani-a-protokol-jmhz).
7. Chcete-li srážet hned, zaškrtněte **Rovnou aktivovat**.
8. Klikněte na **Uložit**.
9. V prvním dotčeném mzdovém běhu zkontrolujte výpočet srážky a závazek
   příjemci.

**Jak poznáte, že je hotovo:** Dohoda je v seznamu ve stavu **Aktivní**. Po
schválení mzdy přibude v detailu v **Pohyby srážek** sražená částka a u limitu
se ukáže „Zbývá do limitu".

## 87.4 Krok za krokem: změna, pozastavení a ukončení

1. V seznamu otevřete dohodu tlačítkem **Detail**.
2. Pro změnu částky nebo podmínek upravte pole, vyplňte **Změna účinná od**
   a klikněte na **Uložit**. Vznikne nová verze dohody.
3. Pro dočasné zastavení klikněte na **Pozastavit**, pro pokračování na
   **Obnovit**.
4. Pro konec srážení klikněte na **Ukončit**. Návrh bez jediného pohybu můžete
   **Zrušit**.

**Jak poznáte, že je hotovo:** V **Historie verzí** je nová verze a stav dohody
odpovídá akci. Sražené částky v **Pohyby srážek** zůstanou.

## 87.5 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| „Dohodu mezitím změnil někdo jiný." | Dohodu mezitím změnil jiný uživatel nebo přibyl pohyb | Formulář se načetl znovu; zkontrolujte hodnoty a uložte znovu. |
| „U srážky ze zákona vyplň den zahájení srážek" | U srážky podle § 147 chybí **Den zahájení srážek** | Vyplňte ho, určuje pořadí vůči exekucím. |
| Srážka se ve mzdě neprovedla nebo je nižší | Dohoda není aktivní a účinná v období, je vyčerpaný limit, nebo na ni podle pořadí nezbyla kapacita | Zkontrolujte stav, účinnost a limit; kapacitu ukáže výsledek srážek ve mzdovém běhu. |
| Srážka se sráží dvakrát | K aktivní dohodě je zadaná i ruční srážka ve vstupech | Ruční vstup odstraňte. |
| Dohodu nejde uložit | Máte k mzdovým vstupům jen právo ke čtení | Požádejte o oprávnění k zápisu. |
| Srážka pokračuje po dosažení limitu | Limit není vyplněný | Doplňte **Celkový limit (Kč)**, nebo dohodu ukončete. |

## 87.6 Podrobnosti a pravidla

### 87.6.1 Právní titul a pořadí

- **Dohoda o srážkách ze mzdy** (§ 146 písm. b) zákoníku práce): pořadí vůči
  exekucím určuje den doručení dohody plátci mzdy.
- **Záloha na mzdu k vrácení** (§ 147 odst. 1 písm. c)), **Nevyúčtovaná
  záloha** (písm. d)) a **Náhrada mzdy bez nároku** (písm. e)): srážka ze
  zákona, ke které souhlas zaměstnance není potřeba. Povinný je **Den zahájení
  srážek**, podle něj se určuje pořadí. Sráží se jen z první třetiny zbytku
  čisté mzdy jako nepřednostní pohledávka. Začínají-li srážky ze zákona týž
  den, jdou v pořadí písmen c), d), e) a dohody až po nich; při stejném dni má
  zákonná srážka přednost před dohodou.

Srážky ze zákona podle § 147 odst. 1 se do zápočtového listu jako pokračující
nepřenášejí: jsou to pohledávky tohoto zaměstnavatele a další plátce mzdy je
srážet nesmí (viz [Zaměstnanci, skončení vztahu](86_Zamestnanci.md#861217-skonceni-vztahu)).

Nejdřív rozhoduje **den doručení dohody nebo zahájení zákonné srážky**.
Při stejném dni mají zákonné tituly přednost v pořadí c), d), e), potom
rozhoduje ruční **Pořadí** (10 až 9999). Stejná skupina se při nedostatku
kapacity uspokojí poměrně. Dohoda bez data se řadí za dohody s datem;
nižší ruční číslo datum nepřebije.

Dohoda doručená před exekučním příkazem může soutěžit v obecné nepřednostní
části. Nejde proto vždy jen o zbytek po všech exekucích. Výpočet dál
respektuje nezabavitelnou částku a pravidla přednostních pohledávek. Je-li uplatněný nárok na vyživovanou osobu nedoložený, mzdový běh
nepustí dohody do vyčerpání kapacity (viz [Srážky a exekuce](88_Srazky_a_exekuce.md#88123-manzel-a-nezabavitelna-castka-dolozeny-duchod)).

### 87.6.2 Částka, limit a stavy

Dohoda má titul, příjemce, druh (**Záloha**, **Stravování**, **Příspěvek**,
**Náhrada škody**, **Jiná srážka**, případně **Srážka z podkladů (bez dohody
podle OZ)** z importu docházky), pořadí, částku a účinnost od-do. Volitelný
celkový limit po vyčerpání srážku zastaví. Ze zbývajícího limitu se odečítají
vlastní srážky i částka **Sraženo předchozím programem (Kč)**. Ta nesmí být
záporná ani vyšší než vyplněný limit a znovu se nesráží. Při zadání procentem se z procenta
a základu uloží pevná částka, protože mzdový běh zmrazuje podklady dřív, než
zná výsledný příjem.

Dohoda prochází stavy **Návrh → Aktivní → Pozastavená → Ukončená**; návrh bez
pohybu lze zrušit (**Zrušená**). Do mzdového běhu vstupuje jen aktivní dohoda
účinná v období. Stav v evidenci není důkazem souhlasu zaměstnance.

Změna nikdy nepřepíše podklady schválené mzdy: uloží se jako nová účinná verze
a historie verzí i pohybů zůstává v detailu. Ukončení srážku zastaví, ale
historii sražených částek nemaže. Při souběžné změně skončí uložení konfliktem
a formulář se načte znovu; poslední zápis nikdy tiše nevyhrává.

### 87.6.3 Kontroly a bezpečnost

Před aktivací ověřte oprávněnost, datum, maximální částku, pořadí a účet
příjemce. Dokument dohody uchovávejte bezpečně. Volitelný odkaz na dokument
nenahrazuje vyplnění účinnosti a pravidla srážky.

Časté chyby:

- smluvní dohoda zařazená jako exekuce nebo naopak,
- srážka pokračující po dosažení limitu,
- neplatný účet příjemce,
- ruční částka zadaná současně s aktivní automatickou dohodou.

## 87.7 Související kapitoly

- [Srážky a exekuce](88_Srazky_a_exekuce.md): exekuce a insolvence
- [Mzdové běhy](80_Mzdove_behy.md): výpočet srážek
- [Platby a úhrady](82_Platby_a_uhrady.md): úhrada příjemci
- [Nastavení mezd](90_Nastaveni_mezd.md#901411-import-dochazky): srážky z importu docházky
