# 100. Daňové konstanty

> Návod pro správce systému: jak otevřít celosystémové daňové konstanty,
> přidat nový rok, přepsat hodnoty po změně zákona a vrátit se k výchozím
> hodnotám dodaným aplikací.

## 100.1 Kdy to potřebujete

Kapitolu otevřete, když:

- se změnily roční sazby, pásma, slevy nebo limity a aplikace ještě nemá
  nové hodnoty,
- začíná nový rok a potřebujete mít připravenou sadu konstant pro další rok,
- se u paušální daně mění měsíční záloha během roku,
- se vlastní přepis ukázal jako chybný a chcete se vrátit k hodnotám
  z aplikace.

<!-- cols: 24 40 36 -->
| Kdy | Co udělat | Kde v aplikaci |
|---|---|---|
| před koncem roku | Ověřit, že existuje sada konstant pro příští rok | `Systém → Daňové konstanty`, tlačítko **Přidat rok** |
| po vyhlášení nových sazeb | Přepsat dotčené hodnoty daného roku | `Systém → Daňové konstanty` |
| při chybě v přepisu | Vrátit rok na výchozí hodnoty | `Systém → Daňové konstanty`, tlačítko **Reset na výchozí** |

## 100.2 Než začnete

- Potřebujete roli administrátora. Stránka je celosystémová, ne nastavení
  aktuální firmy.
- Mějte po ruce platné právní hodnoty daného roku. Změna se projeví u všech
  firem v instalaci.

## 100.3 Krok za krokem: přepsání hodnot roku

1. Otevřete `Systém → Daňové konstanty` (položka je v menu hned pod
   **Sazby a číselníky**). Nejde o nastavení aktuální firmy ani o záložku
   číselníků.
2. Nahoře zvolte **Rok**. Štítek u roku ukazuje, zda jde o **výchozí (z kódu)**,
   nebo **upraveno (přepis)**.
3. Upravte hodnoty ve skupinách formuláře (například **Daň z příjmu**,
   **Pojistné**, **Slevy a zvýhodnění**, **DPH a výkazy**).
4. U paušální daně nastavte u každého období pole **Od** (počáteční měsíc).
   Další změnu měsíční zálohy přidáte odkazem **přidat změnu sazby**. Roční
   částka se dopočte sama (**Ročně (dopočteno):**), neupravuje se ručně.
5. Klikněte na **Uložit**.

**Jak poznáte, že je hotovo:** Aplikace ukáže hlášku **Daňové konstanty
uloženy.** a u roku se objeví štítek **upraveno (přepis)**.

> [!WARNING]
> Změna daňové konstanty ovlivňuje všechny firmy a všechny budoucí přepočty,
> které daný rok používají. Před uložením ověřte celý formulář proti aktuálním
> právním hodnotám. Změna není lokální výjimkou pro jediný doklad.

## 100.4 Krok za krokem: přidání nového roku

1. Otevřete `Systém → Daňové konstanty`.
2. Klikněte na **Přidat rok**. Lze přidat nejvýše rok následující po
   aktuálním kalendářním roce.
3. Aplikace nový rok hned uloží jako kopii posledního roku. Rozvrh paušální
   daně převezme poslední platnou měsíční zálohu jako jediné období od 1. 1.
4. Upravte hodnoty, které se pro nový rok mění, podle kroků
   v [§ 100.3](#1003-krok-za-krokem-prepsani-hodnot-roku) a klikněte na **Uložit**.

**Jak poznáte, že je hotovo:** Aplikace ukáže hlášku, že byl rok přidán
a uložen, a rok je v nabídce **Rok** se štítkem **upraveno (přepis)**.

## 100.5 Krok za krokem: návrat k výchozím hodnotám

1. Otevřete `Systém → Daňové konstanty` a zvolte rok.
2. Klikněte na **Reset na výchozí** a potvrďte dotaz.

**Jak poznáte, že je hotovo:** Aplikace ukáže **Vráceno na výchozí.** a štítek
roku se změní na **výchozí (z kódu)**.

## 100.6 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Tlačítko **Přidat rok** je neaktivní | Přidat jde nejvýše rok následující po aktuálním kalendářním roce | Další rok přidejte až v novém kalendářním roce |
| Starý výpočet ukazuje původní hodnoty | Uložené finální snapshoty se zpětně nepřepisují | Spusťte nový náhled nebo nový výpočet |
| Po přepisu vychází nesmysly | Chybná hodnota v přepisu | **Reset na výchozí** a zadejte hodnoty znovu |

## 100.7 Podrobnosti a pravidla

### 100.7.1 Rok a efektivní hodnoty

Hodnoty jsou verzované po jednotlivých letech. Aplikace pro každý výpočet
vybere efektivní sadu odpovídající danému roku, aby pozdější změna sazeb
nepřepsala pravidla staršího období.

Konstanty zahrnují zejména:

- sazby, pásma, slevy a limity daně z příjmů;
- sociální a zdravotní pojistné a parametry mezd;
- pásma a měsíční rozvrhy paušální daně;
- odpisové parametry;
- limity DPH a kontrolního hlášení;
- zákonné termíny podání.

Mzdové hodnoty na této stránce používá především základní **Mzdová
rekapitulace**. Zkušební agenda **Úplné mzdy** má oddělené, auditovatelné
legislativní sady v **Mzdy → Legislativní pravidla**; jejich stav a schvalování
popisuje kapitola [Legislativní pravidla mezd](92_Legislativni_pravidla_mezd.md).

### 100.7.2 Vlastní přepis

Vestavěné hodnoty lze pro konkrétní rok administrátorsky přepsat. Tlačítko
**Uložit** zapíše vlastní efektivní hodnoty; **Reset na výchozí** vlastní přepis
odstraní a znovu použije hodnoty dodané aplikací. Obě operace se zapisují do
activity logu.

U rozvrhů, jejichž částka se může změnit během roku, se zadává počáteční měsíc
každého období. Typickým příkladem je paušální daň: další období přidá změnu
měsíční zálohy a roční částka se dopočítá jako součet měsíců, neupravuje se
ručně.

### 100.7.3 Dopad změn

Uložené finální snapshoty daňových výpočtů se změnou konstant zpětně
nepřepisují. Nový náhled nebo nový výpočet však už použije aktuální efektivní
sadu.

### 100.7.4 Neziskovky a daňová ztráta

Záložka **Neziskovky** obsahuje roční sazbu a dolní i horní mez snížení
základu daně podle § 20 odst. 7. Sazba se zadává jako desetinné číslo:
**0,30 znamená 30 %**. Oddělené meze platí pro standardní kategorii a pro
oprávněné vysoké školy a výzkumné instituce. Částky zadávejte v celých Kč;
dolní mez nesmí převyšovat horní mez.

Přepis částek nezakládá právní nárok na snížení. Kategorii a způsobilost
poplatníka určuje doložený právní profil příslušného období. Finální
přiznání a evidence jeho daňové úspory používají zachycenou roční sadu,
takže je pozdější přepis číselníku nepřepočítá.

Záložka **Výkaznictví a termíny** obsahuje také počet období pro zpětné uplatnění
daňové ztráty a jeho celkový limit. Tyto parametry samy nepotvrzují vznik
ani dostupnost konkrétní daňové ztráty.

## 100.8 Související kapitoly

- [Daňový optimalizátor](47_Danovy_optimalizator.md) - orientační porovnání
  režimů OSVČ používá tyto konstanty.
- [Legislativní pravidla mezd](92_Legislativni_pravidla_mezd.md) - oddělené
  legislativní sady pro agendu Úplné mzdy.
