# 92. Legislativní pravidla mezd

> Návod, jak zkontrolovat sazby, limity a lhůty, ze kterých počítá mzdový
> modul, jak je případně upravit a jak hlídat, že je připravená sada na příští
> rok. Pro mzdové účetní (čtení) a superadmina (úpravy).

## 92.1 Kdy to potřebujete

- Je podzim a potřebujete ověřit, že pro příští rok existuje účinná sada
  pravidel (bez ní mzdy od 1. ledna nespočítáte).
- Vyšla novela s jinou sazbou, limitem nebo minimální mzdou dřív, než ji
  přinese nová verze aplikace.
- Chcete vědět, které parametry výpočet nepoužívá a proč.
- Chcete doložit, odkud hodnota pochází a kdo ji změnil.

## 92.2 Než začnete

1. **Oprávnění.** Prohlížet může každý s oprávněním ke mzdám. Pravidla jsou
   národní a společná pro všechny firmy, proto je smí měnit jen superadmin
   (oprávnění `payroll.rulesets`); ostatní je vidí jen ke čtení.
2. **Ověřený právní zdroj** změny (zákon, nařízení vlády, sdělení) s přesným
   datem účinnosti.

Ověřenou sadu dodává aplikace účinnou a ručí za ni. Nic schvalovat ani
odklikávat nemusíte; za ručně upravené hodnoty ručí ten, kdo je zadal.

## 92.3 Krok za krokem: kontrola sady pro období

1. Otevřete `Mzdy → Legislativní pravidla mezd`.
2. Nahoře projděte panel **Výhled na příští roky**. U každého roku je stav
   **Pokryto**, **Informace**, **Doplnit letos** nebo **Doplnit ihned**
   a případně seznam chybějících oblastí.
3. U oblasti, kterou kontrolujete (např. **Sociální pojištění**), klikněte na
   **Otevřít** a podívejte se na verze, jejich **Účinnost** a **Stav**.
4. V části **Odkud hodnoty jsou** porovnejte hodnoty se zdrojem.

**Jak poznáte, že je hotovo:** Pro rok, který budete počítat, svítí „Rok … je
pokrytý legislativní sadou." a oblast má právě jednu **Účinné** verzi pro dané
období.

## 92.4 Krok za krokem: úprava hodnoty (superadmin)

1. Otevřete oblast a verzi, kterou měníte.
2. Přepište hodnotu. Peníze zadávejte v korunách, sazby v procentech.
3. Vyplňte **Důvod změny** (např. novela zákona a datum účinnosti).
4. Klikněte na **Uložit**.
5. Zkontrolujte rozdíl proti ověřené sadě (sloupce **Původně** a **Nově**).

**Jak poznáte, že je hotovo:** Aplikace hlásí „Změna uložena." a v historii
verze je zapsané, kdo, kdy, co a proč změnil.

Ruční úpravu zahodíte tlačítkem **Vrátit ověřenou hodnotu**.

## 92.5 Krok za krokem: uvedení verze do provozu (superadmin)

1. U rozpracované verze klikněte na **Označit jako zkontrolované**.
2. Klikněte na **Odborně schválit**.
3. Načtěte **Náhled dopadu před uvedením do provozu**, projděte rozdíl
   parametrů a zaškrtněte, že jste se s náhledem seznámili.
4. Klikněte na **Uvést do provozu**.

**Jak poznáte, že je hotovo:** Verze má stav **Účinné**, předchozí
**Nahrazeno**. Dokud kontrola neprojde, ukáže obrazovka u akce konkrétní důvod
(„Blokuje: …").

## 92.6 Krok za krokem: sada na příští rok

1. Od 1. října (po vyhlášení průměrné mzdy) otevřete `Mzdy → Legislativní
   pravidla mezd`.
2. V **Výhled na příští roky** zkontrolujte příští rok. Stav **Doplnit letos**
   nebo **Doplnit ihned** znamená, že sada chybí.
3. Sadu doplňte, až vyjde nová verze aplikace s ověřenou sadou. Do té doby
   o její založení požádejte provozovatele; tlačítko pro založení sady na nový
   rok obrazovka nemá.

**Jak poznáte, že je hotovo:** Příští rok má stav **Pokryto**.

> [!WARNING]
> Bez účinné sady pro nový rok mzdový modul od 1. ledna nespočítá ani jednu
> výplatu. Ověřte to raději na podzim, ne v lednu.

## 92.7 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Parametr má štítek **Čeká na vyhlášení** | Hodnota ještě nevyšla ve Sbírce (např. stravné pro příští rok) | Hodnota přijde aktualizací aplikace. Příspěvek na stravování lze do té doby schválit, ale celý se zdaní a pojistí. Po vyhlášení limitu dorovnejte osvobozenou část opravou vstupu; běh na to upozorní. |
| Parametr má štítek **Posuzuje se jinde** | Nárok se dokládá u konkrétního zaměstnance nebo případu | Klikněte na odkaz u parametru (např. **Roční zúčtování**) a nárok zapište tam. |
| Parametr má štítek **Aplikace nepočítá** | Zvláštní případ, který aplikace nepočítá (např. zahraniční cesta) nebo nepotvrzená hodnota roku 2025 | Týká se vás, jen když na takový případ narazíte; postup je u parametru v části **Co s tím**. |
| Pravidla jsou jen ke čtení | Nejste superadmin | Změnu nechte provést superadmina. |
| Akce verze je nedostupná s „Blokuje: …" | Mezera nebo překryv účinnosti, nebo neplatný kontrolní součet | Upravte účinnost podle důvodu; u kontrolního součtu kontaktujte podporu. |
| **Neplatný kontrolní součet** | Neporušenost některé sady nejde ověřit | Obraťte se na podporu, která prověří instalaci. |
| „Uložená úprava pravidel je nekonzistentní" | Úprava v databázi nesedí | Výpočet používá ověřená pravidla z aplikace; předejte technické údaje podpoře. |
| „Náhled už neodpovídá aktuálnímu kandidátu" | Verze se mezitím změnila | Načtěte náhled znovu. |
| Příprava JMHZ za rok 2025 se odmítne | JMHZ platí až od 1. 1. 2026 | Za rok 2025 se JMHZ nepodává. |

## 92.8 Podrobnosti a pravidla

### 92.8.1 Které roky jsou pokryté

Ověřená sada je v aplikaci pro roky **2025**, **2026** a **2027**. Rok 2025 je v ní proto,
aby šlo opravit starší měsíc a provést roční zúčtování za rok 2025 skutečnými
pravidly toho roku, ne dnešními.

> [!WARNING]
> **Exekuční srážky se v roce 2025 počítaly jinou formulí než v roce 2026.**
> V roce 2025 se z části základu srážely na povinného **dvě třetiny** a hranice
> plně zabavitelného zbytku byla **jedenapůlnásobek** základní částky. Od roku
> 2026 je poměr **85/100**, hranice **1,9násobek** a k částce na bydlení přibyl
> samostatný paušál na energie. Opravujete-li rok 2025, vyjde jiné číslo než
> při stejném zadání v roce 2026, a je to správně. Aplikace bere podíly ze sady
> účinné pro dané období.

Několik hodnot roku 2025 zůstává **nepotvrzených**, protože se dostupné zdroje
rozcházejí: sleva pro pracujícího poplatníka v důchodu, sleva u zemědělské
dohody o provedení práce a zvláštní sazby pojistného zaměstnavatele podle § 7
odst. 1 zákona č. 589/1992 Sb. (záchranné sbory, riziková práce). Aplikace
nedosadí odhad: výpočet, který je potřebuje, se zastaví a označí k ruční
kontrole. Chcete-li je použít, doplňte je podle ověřeného zdroje. Běžné sazby
sociálního a zdravotního pojištění doložené jsou.

Rok 2025 záměrně neobsahuje termíny podání, číselníky a datové věty JMHZ ani
parametry povinného spoření u rizikové práce; obojí je účinné až od roku 2026.
JMHZ zavedl zákon č. 323/2025 Sb. od 1. 1. 2026, za rok 2025 se proto nepodává
a aplikace jeho přípravu odmítne. Rok 2025 je v aplikaci pro **výpočet a opravu
mezd**, ne pro podání.

### 92.8.2 Příští rok bez sady

Sada pro příští rok se odvíjí od průměrné mzdy, kterou vláda vyhlašuje
nařízením až na podzim předchozího roku. Nařízení musí vyjít **do 30. září**,
takže od 1. října je sada sestavitelná. Minimální mzda, stravné a nezabavitelné
částky obvykle vycházejí později; dokud nevyjdou, ukazuje karta oblasti
dotčený parametr jménem jako **Čeká na vyhlášení**.

Prázdná kostra sady se záměrně nezakládá: rok s prázdnou sadou by se tvářil
jako pokrytý, zatímco výpočet by selhal. Za pokrytý se počítá jen **účinná**
sada, rozpracovaná verze rok nerozsvítí. Panel **Výhled na příští roky** proto
stojí nad oblastmi: oblasti mohou vypadat v pořádku (všechny verze účinné), ale
žádná nemusí pokrývat leden. Stav **Informace** znamená, že hodnoty ještě nejsou
vyhlášené, **Doplnit letos** že se vyhlašují, **Doplnit ihned** že jsou
vyhlášené a sada chybí.

Hodnoty se doplňují v této agendě bez zásahu do programu, stejně jako u jiné
verze.

### 92.8.3 Oblasti, verze a stavy

Pravidla jsou rozdělená do oblastí: **Daň z příjmů ze závislé činnosti**,
**Sociální pojištění**, **Zdravotní pojištění**, **Hranice a minimální mzda**,
**Průměry a náhrady**, **Cestovní náhrady**, **Exekuční srážky**, **Termíny
a lhůty**, **Číselníky** a **Verze podání**. Každá má vlastní verze s obdobím
účinnosti. Obecné daňové hodnoty popisuje [Daňové konstanty](100_Danove_konstanty.md).

Ověřená sada se používá, dokud ji nikdo nezmění. Úprava se uloží jen jako
změněné hodnoty, ostatní se berou dál z ověřené sady, a projeví se bez nové verze
programu. Peníze se zadávají v korunách a sazby v procentech; převod na vnitřní
jednotky řeší aplikace.

Verze prochází stavy **Rozpracováno → Technicky zkontrolováno → Odborně
schváleno → Účinné → Nahrazeno**. Výpočet čerpá jen z účinné verze. Před
schválením a uvedením do provozu se kontroluje, že v období účinnosti oblasti
nevzniká mezera ani překryv a že uložené hodnoty odpovídají kontrolnímu součtu.
Technická kontrola není odborné ani právní schválení; u dodané sady odborné
schválení evidované není, hodnoty jsou doložené zdrojem a technickou kontrolou.
Uvedení do provozu vyžaduje aktuální náhled dopadu a výslovné potvrzení;
použije se pro nové snímky v období účinnosti a dříve uložené snímky nemění.
Dopad v korunách bez uzamčeného vstupu náhled nepočítá.

Ke každé verzi je vidět rozdíl proti ověřené sadě (co přibylo, co zmizelo, jak
se změnila hodnota) a historie změn: kdo, kdy, co a proč. Historii nelze mazat
ani přepisovat. Schválí-li verzi tentýž člověk, který ji upravil, změna projde,
ale obrazovka na to upozorní. Jedna oprávněná osoba tak zvládne pravidlo založit,
ověřit i použít bez druhé osoby, auditní stopa zůstává.

### 92.8.4 Parametry mimo automatický výpočet

Několik parametrů záměrně nemá číslo, ze kterého by výpočet čerpal. Karta
oblasti je vypisuje jménem v rámečku **Mimo automatický výpočet**, rovnou bez
otevírání verze, a u každého uvádí **Proč** a **Co s tím**. Když oblast žádný
takový parametr nemá, rámeček se nezobrazí. Ostatní parametry oblasti se
používají normálně.

Štítek říká, o jakou situaci jde:

- **Čeká na vyhlášení (očekáváno …)**: zákon hodnotu dá, jen ještě nevyšla.
  Typicky osvobozený příspěvek na stravování za směnu, který je 70 % horní
  hranice stravného (§ 6 odst. 9 písm. b) ZDP); tu stanoví vyhláška podle § 189
  zákoníku práce obvykle v prosinci. Hodnota přijde aktualizací aplikace. Do té
  doby lze příspěvek na stravování schválit, ale **celé plnění se zdaní
  a pojistí jako mzda**. Mzdový běh upozorní, že limit chybí. Po jeho
  vyhlášení dorovnejte osvobozenou část opravou vstupu, případně opravnou
  revizí již schválené mzdy. Samotná aktualizace původní rozpad nepřepíše.
- **Posuzuje se jinde**: nárok se nedá posoudit ze sady pravidel, ale
  z dokladů v jiné agendě, kam vede odkaz. Sleva na manžela se uplatňuje až
  v **Ročním zúčtování** podle prohlášení a dokladů zaměstnance; nárok na
  náhradu mzdy a průměrný výdělek se posuzují u konkrétní nepřítomnosti v agendě
  **Nepřítomnosti**; termíny a odeslání hlášení řeší stránka **Podání**.
- **Aplikace nepočítá**: zvláštní případ, který aplikace nepočítá, například
  zahraniční pracovní cesty, sleva u zemědělské dohody o provedení práce nebo
  nepotvrzené zvláštní sazby roku 2025. Týká se vás, jen když na takový případ
  narazíte.

U parametru je uvedené i to, které sady se týká (např. „sada 2027").

Volnočasová plnění podle § 6 odst. 9 písm. d) bodu 2 ZDP (sport, kultura,
vzdělávací a předškolní zařízení) jsou od roku 2027 osvobozená bez číselného
limitu a sada to tak uvádí. Rekreace a zájezdy mají od roku 2027 vlastní limit
20 000 Kč za rok podle bodu 3, viz [Koše benefitů](89_Kose_benefitu.md).

### 92.8.5 Bezpečnost a časté chyby

Chybějící nebo překrývající se účinnost výpočet zastaví nebo vyvolá kontrolu.
Účinná verze neznamená automaticky právně ověřenou, rozhoduje zdroj. Odkaz na
zdroj je volitelná důkazní informace, ne podmínka výpočtu; nikdy do něj
nevkládejte přihlašovací údaje. Změna pravidla může ovlivnit mzdy, platby,
účetnictví i podání. Před ostrým použitím proveďte kontrolní výpočet běžných
i hraničních případů.

Časté chyby:

- použití současných hodnot pro starší období,
- přepsání historické sady místo nové verze,
- překryv dvou sad účinných ve stejný den,
- kontrola jen běžného výpočtu bez limitních a souběžných případů.

## 92.9 Související kapitoly

- [Daňové konstanty](100_Danove_konstanty.md)
- [Mzdové běhy](80_Mzdove_behy.md)
- [Roční zúčtování](84_Rocni_zuctovani.md)
- [Srážky a exekuce](88_Srazky_a_exekuce.md)
