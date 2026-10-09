# 44. Souhrnné hlášení (DPHSHV)

> Návod, jak sestavit a podat souhrnné hlášení (SH) k DPH. Vykazuje uskutečněná
> plnění pro osoby registrované k DPH v jiném členském státě EU. Pro plátce
> i identifikované osoby, které takové plnění uskutečnily.

## 44.1 Kdy to potřebujete

- Dodali jste zboží osobě registrované k DPH do jiného členského státu EU.
- Poskytli jste službu s místem plnění v jiném členském státě EU (B2B).
- Uskutečnili jste třístranný obchod jako prostřední osoba.
- Už podané hlášení je třeba opravit (následné hlášení, viz [§ 44.4](#444-krok-za-krokem-nasledne-souhrnne-hlaseni)).

Samotné pořízení zboží nebo služby ze zahraničí do SH nepatří. Vykazuje se
v přiznání DPH, případně v kontrolním hlášení.

<!-- cols: 24 40 36 -->
| Kdy | Co udělat | Kde v aplikaci |
|---|---|---|
| po skončení měsíce, zpravidla do 25. dne | Sestavit a podat řádné SH za měsíc | `Daně → Souhrnné hlášení` |
| po skončení čtvrtletí, zpravidla do 25. dne | Totéž za čtvrtletí, jen když jste ve čtvrtletí poskytovali výhradně služby s kódem 22 | `Daně → Souhrnné hlášení`, přepínač **Kvartálně** |
| do 15 dnů od zjištění chyby | Podat následné SH | `Daně → Souhrnné hlášení`, **Typ podání** |

## 44.2 Než začnete

- Doklady za období musí být vystavené (ne koncepty), s DUZP v období a se správnou klasifikací DPH.
- Odběratel musí mít na kartě kontaktu zemi EU mimo ČR a platné EU DIČ (viz [Klienti](18_Klienti.md)).
- K tlačítku **Stáhnout XML** potřebujete oprávnění k exportu výkazů. Bez něj můžete náhled jen prohlížet.
- Chcete-li podat následné hlášení, musí být řádné hlášení za stejné období označené v archivu jako podané (viz [§ 44.5](#445-krok-za-krokem-export-a-dukaz-podani)).

## 44.3 Krok za krokem: řádné souhrnné hlášení

1. Otevřete `Daně → Souhrnné hlášení`.
2. Zvolte období. Přepínač **Měsíčně / Kvartálně** nastavuje druh období, pak vyberte měsíc a rok, případně čtvrtletí (Q1-Q4) a rok.
3. V poli **Typ podání** nechte **Řádné**.
4. Zkontrolujte upozornění nad náhledem a údaje nahoře: **Počet řádků**, **Celkem v EU** a **Termín podání**.
5. V tabulce **Řádky výkazu** porovnejte stát, DIČ kupujícího, protistranu, kód, typ plnění, počet dokladů a hodnotu se zdrojovými doklady. Jeden řádek může zastupovat více faktur.
6. Klikněte na **Stáhnout XML**. Aplikace XML zvaliduje a uloží do archivu podání jako stažené.
7. Nahrajte XML do formuláře EPO na portálu Finanční správy a podání odešlete.

**Jak poznáte, že je hotovo:** V `Daně → EPO podání a archív` je záznam označený jako podaný a máte uložené potvrzení z portálu (viz [§ 44.5](#445-krok-za-krokem-export-a-dukaz-podani)).

> [!WARNING]
> Čtvrtletní hlášení je dovolené jen při výhradním poskytování služeb s kódem 22. Dodání zboží (kód 20) nebo třístranný obchod (kód 31) vyžaduje měsíční režim (§ 102 odst. 3 zákona 235/2004 Sb.). Aplikace na neslučitelnou kombinaci upozorní, export ale technicky nezablokuje. Prázdné SH se nepodává.

## 44.4 Krok za krokem: následné souhrnné hlášení

Použijte, když zjistíte chybu v už podaném hlášení. Následné SH se podává do 15 dnů od zjištění (§ 102 odst. 6 zákona o DPH).

1. Otevřete `Daně → Souhrnné hlášení` a zvolte stejné období, jaké opravujete.
2. V poli **Typ podání** zvolte **Následné (§ 102/6 - oprava už podaného)**.
3. Vyplňte **Datum zjištění**. Slouží jen k výpočtu 15denní lhůty, do XML nejde.
4. Zkontrolujte tabulku řádků. Obsahuje jen opravné řádky, ne celé hlášení.
5. Klikněte na **Stáhnout XML** a podejte ho stejně jako řádné hlášení.

**Jak poznáte, že je hotovo:** Stažené XML je v archivu podání s variantou následné a po podání ho označíte jako podané.

Jak se opravné řádky tvoří, vysvětlují [Podrobnosti](#4474-jak-se-tvori-nasledne-hlaseni). Bez podaného řádného hlášení za dané období se následné sestavit nedá.

## 44.5 Krok za krokem: export a důkaz podání

1. Stažením XML se záznam uloží do archivu podání. Samotné stažení neznamená odeslání.
2. Po nahrání na portál zkontrolujte výsledek, odešlete formulář a uschovejte potvrzení.
3. Otevřete `Daně → EPO podání a archív`, najděte záznam (formulář **Souhrnné hlášení**) a klikněte na **Označit jako podané**.

**Jak poznáte, že je hotovo:** Záznam v archivu je označený jako podaný. Bez tohoto kroku archiv není spolehlivým dokladem skutečného podání.

> [!TIP]
> Prázdný náhled sám neprokazuje, že firma neměla vykazované plnění. Zkontrolujte klasifikaci plnění, zemi a DIČ odběratele.

## 44.6 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| Náhled je prázdný, hlášení **V tomto měsíci nejsou žádné EU dodávky** | Faktura je koncept nebo stornovaná, nemá DUZP v období, klient nemá EU DIČ nebo zemi EU, nebo klasifikace DPH není 20, 22 ani 31 | Opravte doklad nebo kontakt a náhled se přepočítá |
| Varování, že prefix DIČ nesouhlasí se zemí adresy | Kód státu v DIČ a země adresy se liší | Opravte DIČ nebo zemi na kartě kontaktu. Rozhoduje prefix DIČ. |
| Varování u záporné hodnoty plnění se jménem protistrany a částkou | Dobropis do jiného členského státu převyšuje v období dodávky téže protistraně. EPO řádné hlášení se zápornou hodnotou odmítne. | Dodávku opravte následným hlášením (viz [§ 44.4](#444-krok-za-krokem-nasledne-souhrnne-hlaseni)) |
| Varování o chybějícím kurzu u dodávky v cizí měně | Faktura nemá zafixovaný kurz | Při stažení XML se kurz doplní z ČNB, náhled jen varuje |
| Následné hlášení nejde sestavit, hláška o chybějícím podaném řádném hlášení | Řádné SH za období není v archivu označené jako podané | V `Daně → EPO podání a archív` označte jeho záznam jako podaný |
| Tlačítko **Stáhnout XML** chybí | Chybí oprávnění k exportu výkazů | Požádejte správce firmy o oprávnění |

## 44.7 Podrobnosti a pravidla

### 44.7.1 Co se zahrne

Zdroj tvoří řádky vystavených, ne-stornovaných a ne-konceptních faktur ve stejné
evidenci DPH jako [přiznání k DPH](41_Vykazy_DPH.md). Rozhoduje DUZP, případně datum
vystavení, nikoli úhrada. Protistrana musí mít zemi EU mimo ČR a použitelné EU DIČ.

DIČ se normalizuje bez mezer a oddělovačů. Kód státu v hlášení je stát, který DIČ
přidělil: nese-li DIČ prefix členského státu, rozhoduje prefix, jinak země adresy.
Do čísla DIČ jde DIČ **bez** tohoto prefixu, jak vyžaduje XSD. Na kartě kontaktu
tedy můžete DIČ vést s prefixem (`PL1234567890`) i bez něj, do hlášení jde vždy
stát `PL` a číslo `1234567890`. Řecký prefix `GR` se převádí na `EL`.

Podporované mapování:

<!-- cols: 24 18 58 -->
| Klasifikace DPH | Kód plnění v XML | Význam |
|---|---:|---|
| 20 | 0 | Dodání zboží do jiného členského státu |
| 31 | 2 | Třístranný obchod, prostřední osoba |
| 22 | 3 | Poskytnutí služby s místem plnění v EU |

Kód 1 formuláře pro přemístění vlastního zboží nemá samostatnou vestavěnou
klasifikaci. Takový případ doplňte ručně v EPO a nechte zkontrolovat poradcem.
Faktura bez DIČ nebo bez podporované klasifikace se do XML nezařadí, proto před
exportem porovnejte seznam faktur s náhledem. Nejde o bezpečnou náhradu kontroly
VIES.

### 44.7.2 Seskupení a zaokrouhlení

Řádky se seskupí podle státu, normalizovaného DIČ a typu plnění. Hodnota je základ
v Kč ze všech dokladů skupiny a počet je počet různých faktur. Hodnota plnění se do
XML zapisuje na celé Kč zaokrouhlením **od nuly** (kladná nahoru, záporná dolů).
Souhrnný náhled proto může zobrazovat haléře, zatímco jednotlivý řádek XML celé koruny.

Zaokrouhlení nahoru předepisuje struktura souhrnného hlášení na EPO: „Celková hodnota
plnění se zaokrouhlí na celé koruny nahoru. Celková hodnota plnění musí být vždy celé
číslo." Zaokrouhluje se každý řádek zvlášť, kdežto přiznání k DPH sčítá haléře
a zaokrouhluje až součet na ř. 20, 21 a 31. **Celkem v EU** v náhledu je součet
zaokrouhlených řádků, tedy to, co hlášení skutečně obsahuje, a může být o něco vyšší
než přiznání, nejvýše o 1 Kč na každý řádek. Náhled pod částkou ukáže součet před
zaokrouhlením, který s přiznáním souhlasí. Křížová kontrola přiznání se souhrnným
hlášením porovnává částky před zaokrouhlením, takže tento rozdíl jako nesoulad nehlásí.

**Záporná hodnota plnění** (dobropis do jiného členského státu převyšuje v období
dodávky téže protistraně) do řádného hlášení nepatří. EPO řádné hlášení se zápornou
hodnotou odmítne. Náhled na takový řádek upozorní se jménem protistrany a částkou,
ať to zjistíte před odesláním, ne až z chybové hlášky portálu.

### 44.7.3 Období a typ podání

Hlášení lze sestavit měsíčně nebo čtvrtletně. Čtvrtletí je určeno jen pro plátce,
kteří v něm poskytují výhradně služby s kódem 22. Aplikace nabízí řádné a následné
hlášení. Zvláštní případy dokončete ručně na portálu podle pokynů správce daně.
Termín podání je zpravidla do 25. dne po skončení období, u následného hlášení
15 dnů od zjištění změny.

### 44.7.4 Jak se tvoří následné hlášení

Následné SH se nepodává jako celý výkaz znovu ani jako rozdíl částek. Podává se jako
**opravné řádky** proti stavu, který za období drží VIES. Ten aplikace složí z posledního
podaného řádného hlášení a všech následujících podaných následných hlášení za totéž
období. Pro každou trojici stát, DIČ a kód plnění platí:

<!-- cols: 50 50 -->
| Situace | Co se vykáže |
|---|---|
| řádek je ve stavu VIES, ale ne v aktuálních datech | jen storno řádku |
| řádek je v obou a liší se hodnota nebo počet | storno původního řádku a nový řádek se správnou hodnotou |
| řádek je jen v aktuálních datech | jen nový řádek |
| řádek je v obou a shoduje se | nevykazuje se (VIES by ho započetl podruhé) |

Storno řádek opakuje hodnotu a počet plnění z rušeného řádku. Bez podaného řádného
hlášení se následné sestavit nedá, protože není co stornovat.

### 44.7.5 Náhled a kontrola před exportem

Po změně období se náhled přepočítá a ukáže počet souhrnných řádků, celkovou hodnotu
v Kč a termín podání. Při čtvrtletním režimu se vybírá čtvrtletí, při měsíčním
konkrétní měsíc.

### 44.7.6 Archiv podání a oprávnění

Stažené XML projde strukturální validací a uloží se do archivu podání jako stažené.
Stažení samo neznamená odeslání. Tlačítko pro stažení je dostupné uživateli s právem
exportovat výkazy. Uživatel bez tohoto práva může náhled zkontrolovat, ale XML nestáhne.

## 44.8 Související kapitoly

- [Výkazy DPH](41_Vykazy_DPH.md)
- [Kniha DPH](42_Kniha_DPH.md)
- [OSS](45_OSS.md)
- [Archiv podání a rekonciliace](49_Archiv_podani_a_rekonciliace.md)
