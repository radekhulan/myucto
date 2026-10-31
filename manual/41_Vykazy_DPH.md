# 41. Výkazy DPH (DPHDP3 + KH)

> Návod, jak z MyÚčta sestavit podklady pro Finanční správu: přiznání k DPH (DPHDP3), kontrolní hlášení (DPHKH1) a OSS přiznání (OSSEI1). Najdete tu i postup, jak je podat přes EPO a jak opravit už podané. Pro plátce DPH, identifikované osoby a jejich účetní.

Výkazy najdete v menu **Daně**, archiv podání jako poslední bod téhož menu (`Daně → EPO podání a archív`). Související výkazy a exporty mají vlastní kapitoly: [Kniha DPH](42_Kniha_DPH.md) (interní žurnál), [Souhrnné hlášení](44_Souhrnne_hlaseni.md) (EU dodání B2B) a [Hromadný export](48_Hromadny_export.md) (ZIP balíček pro účetní). Rozdíl mezi staženým a skutečně podaným XML vysvětluje [Archiv podání a daňová rekonciliace](49_Archiv_podani_a_rekonciliace.md).

**Volba období:** Přiznání DPH, kontrolní hlášení i Kniha DPH přebírají výchozí měsíční nebo čtvrtletní režim z nastavení firmy. Souhrnné hlášení se řídí druhem plnění, proto začíná měsíčně a svůj přepínač Měsíčně / Kvartálně si pamatuje samostatně. Bez zapamatované volby se otevře poslední celé uzavřené období: například 15. října září, u čtvrtletní firmy třetí čtvrtletí. V lednu jde o prosinec nebo čtvrté čtvrtletí předchozího roku.

Vybrané období se pamatuje pro konkrétního uživatele a firmu v rámci karty prohlížeče, včetně pracovních panelů a obnovení stránky. Přechodem do dalšího výkazu se zachová; odhlášení tuto volbu vymaže. Již otevřený výkaz v jiném panelu se sám nepřepne. Odkaz s konkrétním obdobím má před zapamatovanou volbou přednost. Pokud prohlížeč ukládání nepovoluje, zůstane k dispozici výchozí poslední uzavřené období a ruční výběr.

Kontrolní hlášení právnické osoby zůstává měsíční. Měsíční náhledy oprav § 74b a obnov § 46 sdílejí jednu společnou volbu, čtvrtletní OSS si pamatuje vlastní; při prvním otevření mohou převzít rok a měsíc ze společného výběru DPH. Jejich pevná periodicita nepřepíše režim ostatních výkazů. Roční koeficient, roční seznamy oprav, data doručení a období jednotlivých evidovaných oprav zůstávají samostatná. Automatický výběr období se neřídí stavem podání v archivu.

## 41.1 Kdy to potřebujete

- Skončil měsíc nebo čtvrtletí a musíte podat přiznání k DPH a kontrolní hlášení.
- Finanční úřad vás vyzval k odpovědi na kontrolní hlášení.
- Zjistili jste chybu v už podaném přiznání nebo hlášení.
- Prodáváte zboží nebo služby spotřebitelům do jiných států EU a podáváte OSS.
- Uplatňujete krácený odpočet podle § 76 (společné vstupy pro zdanitelná i osvobozená plnění).
- Dlužník vám nezaplatil, nebo vy jste nezaplatili dodavateli déle po splatnosti (§ 46, § 74b).
- Firma se stala plátcem nebo zrušila registraci (§ 79, § 79a), případně musíte opravit chybně určenou výši daně (§ 43).
- Mění se sazba DPH.

<!-- cols: 26 44 30 -->
| Kdy | Co udělat | Kde v aplikaci |
|---|---|---|
| zpravidla do 25. dne po skončení měsíce (u čtvrtletního plátce kvartálu) | Sestavit a podat přiznání k DPH | `Daně → DPH přiznání` |
| ve stejné lhůtě | Sestavit a podat kontrolní hlášení (právnická osoba vždy měsíčně) | `Daně → Kontrolní hlášení` |
| ve stejné lhůtě | Podat souhrnné hlášení, pokud jste měli EU plnění | `Daně → Souhrnné hlášení`, viz [Souhrnné hlášení](44_Souhrnne_hlaseni.md) |
| po skončení čtvrtletí | Podat OSS přiznání, pokud je zapnutý režim OSS | `Daně → OSS přiznání` |
| před každým podáním | Projít upozornění, koncepty a doklady čekající na schválení | stránky výkazů |
| začátek roku | Nastavit zálohový koeficient § 76, pokud uplatňujete krácený odpočet | `Nástroje → Koeficient krácení (§76)` |
| po skončení roku | Provést roční vypořádání koeficientu, vykáže se v přiznání za poslední období roku | `Nástroje → Koeficient krácení (§76)` |
| měsíčně, kdy máte neuhrazené závazky | Zaevidovat opravu odpočtu u neuhrazených závazků | `Nástroje → Oprava odpočtu (§74b)` |
| podle potřeby | Zaevidovat opravu u nedobytné pohledávky a obnovu po úhradě | `Nástroje → Nedobytné pohledávky (§46)` |
| podle potřeby | Zaevidovat opravu § 43, odpočet při registraci nebo zrušení registrace | `Daně → Opravy DPH (§43, §79)` |

## 41.2 Než začnete

1. **Daňové nastavení firmy.** V `Nastavení → Daně a účetnictví`, v boxu **Daňové nastavení (EPO výkazy DPH/KH)**, vyplňte **Typ poplatníka** (fyzická osoba nebo právnická osoba), **Perioda DPH přiznání** (měsíční nebo kvartální), **Kód finančního úřadu** a případně **Kód územního pracoviště (ÚzP)**. Povinné je také **DIČ** v identifikaci firmy. Volitelně doplňte **CZ-NACE klasifikaci**, datovou schránku a **Sestavitel přiznání (účetní)**. Přehled všech polí je v [§ 41.13.1](#41131-pole-epo-a-vetap).
2. **Údaje podnikatele nebo oprávněné osoby.** U fyzické osoby zkontrolujte v `Nastavení → Daně a účetnictví` část **Osobní údaje podnikatele**. U právnické osoby vyplňte v části **Oprávněná osoba (jednatel / podpisující)** jméno, příjmení a postavení osoby, která přiznání podepisuje (typicky jednatel).
3. **Plátcovství DPH.** Rozhoduje stav ke konci období výkazu, ne dnešní stav. Historii plátcovství vedete v témže nastavení v bloku **Plátcovství DPH**.
4. **Doklady.** Doklady za období musí být vystavené nebo přijaté (ne koncepty) a mít správnou **Klasifikaci DPH**. Chybějící klasifikaci doplní automatika, viz [§ 41.13.10](#411310-automaticke-prirazeni-klasifikace).
5. **OSS.** Stránka `Daně → OSS přiznání` se objeví až po zapnutí režimu OSS v `Nastavení → Daně a účetnictví`, viz [Režim OSS](45_OSS.md).
6. **Oprávnění.** Tlačítko **Stáhnout XML** potřebuje oprávnění exportovat výkazy. Zápis oprav (§ 74b, § 43, § 79, § 46) potřebuje oprávnění finalizovat výkazy.

> [!WARNING]
> Právnické osoby podávají kontrolní hlášení vždy měsíčně (§ 101e odst. 1 ZDPH). Fyzické osoby ho podávají ve stejné lhůtě jako přiznání, tedy kvartálně, jsou-li kvartálním plátcem (§ 101e odst. 2). Přepínač **Měsíčně / Kvartálně** se na stránce Kontrolní hlášení zobrazí jen fyzickým osobám.

## 41.3 Krok za krokem: přiznání k DPH (DPHDP3)

Lhůta: zpravidla 25. den po skončení měsíce, u čtvrtletního plátce po skončení čtvrtletí.

1. Otevřete `Daně → DPH přiznání`.
2. Přepínačem **Měsíčně / Kvartálně** zvolte druh období. Výchozí hodnota vychází z nastavení **Perioda DPH přiznání**. Pak vyberte měsíc a rok, případně čtvrtletí (Q1-Q4) a rok.
3. V poli **Typ podání** nechte **Řádné**.
4. Přečtěte upozornění nad kartami (sekce **Upozornění**, žlutý a červený rámeček). Odstraňte jejich příčiny, než budete pokračovat. Typicky jde o koncepty, doklady čekající na schválení nebo nesoulad s kontrolním hlášením (viz [§ 41.12](#4112-kdyz-neco-nejde)).
5. Zkontrolujte čtyři karty: **DPH na výstupu**, **DPH na vstupu**, **Daň k odvodu** (nebo **Nadměrný odpočet**) a **Termín podání** s odpočtem dnů.
6. Projděte tabulky **DPH na výstupu (řádky 1-29)** a **DPH na vstupu (řádky 40+)**. U každého řádku vidíte kód, popis, základ a DPH. Nabídka **…** v posledním sloupci řádku otevře Knihu DPH za stejné období, omezenou na odpovídající sekce. Informační řádky bez dokladové sekce tuto akci nemají. Porovnejte součty se seznamem faktur za období.
7. Pod kartami je přehled **Vývoj DPH (12 měsíců)** pro rychlé porovnání s předchozími obdobími. Žlutá **Predikce** ukazuje odhad včetně konceptů. Do přiznání se promítne až po dokončení konceptů.
8. Klikněte na **Stáhnout XML**. Stránka najde-li nesoulad s kontrolním nebo souhrnným hlášením, nebo s účtem 343, zeptá se na potvrzení. Po stažení se otevře `Daně → EPO podání a archív`.
9. Podání dokončete na portálu EPO podle [§ 41.6](#416-krok-za-krokem-podani-na-portalu-epo-a-dolozeni).
10. V podvojném účetnictví zkontrolujte panel **Interní doklad zúčtování DPH**. Po podání přiznání má ukazovat **Sedí** ([§ 41.13.6](#41136-prevod-dph-na-zuctovaci-ucet)).

**Jak poznáte, že je hotovo:** V `Daně → EPO podání a archív` je záznam označený jako **Podáno** a máte uložené potvrzení z portálu EPO.

> [!WARNING]
> Stažení XML neznamená odeslání. Teprve zápis **Označit jako podané** v archivu ukazuje, že přiznání skutečně odešlo, a jen takové podání slouží jako základ pro dodatečné přiznání.

## 41.4 Krok za krokem: kontrolní hlášení (DPHKH1)

Lhůta: stejná jako u přiznání. Identifikovaná osoba kontrolní hlášení nepodává.

1. Otevřete `Daně → Kontrolní hlášení`.
2. Zvolte období. Právnická osoba má jen měsíc a rok. Fyzická osoba může přepínačem **Měsíčně / Kvartálně** zvolit čtvrtletí.
3. V poli **Typ podání** nechte **Řádné**.
4. Přečtěte upozornění. Doklady čekající na schválení nebo zamítnuté stránka vyjmenuje a u dokladů se samovyměřením (sekce A.2 a B.1) vyžaduje při stažení potvrzení.
5. Zkontrolujte kartu **Termín podání** a počty řádků v sekcích: **A.1**, **A.2**, **A.4**, **A.5** (vystavené) a **B.1**, **B.2**, **B.3** (přijaté). Nabídka **…** v posledním sloupci oddílu otevře soupis jeho dokladů za stejné období. Sekce A.5 a B.3 jsou sumace (**agregováno**); soupis zobrazí jejich jednotlivé doklady. Rychlé odpovědi na výzvu bez oddílů tyto akce nenabízejí.
6. Klikněte na **Stáhnout XML**. Po stažení se otevře `Daně → EPO podání a archív`.
7. Podání dokončete podle [§ 41.6](#416-krok-za-krokem-podani-na-portalu-epo-a-dolozeni).

**Jak poznáte, že je hotovo:** Záznam kontrolního hlášení je v archivu označený jako **Podáno** a máte potvrzení z portálu.

> [!TIP]
> Přiznání a kontrolní hlášení se sestavují ze stejných dat. Nejdřív podejte přiznání, které zkontroluje shodu s hlášením (viz [§ 41.13.5](#41135-krizova-kontrola-s-kh-sh-a-uctem-343)), pak hlášení.

Odkazy z řádků přiznání a oddílů hlášení otevírají podklady v pravém pracovním panelu, pokud je dostupný. Při jediném panelu se podklady otevřou v něm. Odkaz nese období, za které je výkaz právě načtený, a Kniha DPH ho zobrazí; zapamatovanou volbu období ostatních výkazů tím nezmění. Kniha DPH je pracovní přehled aktuálních dat včetně konceptů; neukazuje rozdíl dodatečného přiznání ani uložené podání. Pro kontrolu skutečně podaného KH použijte soupis z [Archivu podání](49_Archiv_podani_a_rekonciliace.md).

## 41.5 Krok za krokem: oprava už podaného přiznání nebo hlášení

Typ opravy záleží na tom, zda ještě běží lhůta pro podání.

<!-- cols: 26 36 38 -->
| Co opravujete | Volba v poli **Typ podání** | Jak se počítá |
|---|---|---|
| přiznání k DPH ještě v lhůtě | **Opravné (§ 138 - před lhůtou)** | nahrazuje řádné přiznání, počítá se znovu celé |
| přiznání k DPH po lhůtě | **Dodatečné (§ 141 - po lhůtě)** | vykáže se jen rozdíl proti poslední známé dani |
| kontrolní hlášení ještě v lhůtě | **Řádné/opravné (§ 101f/1 - před lhůtou)** | nahrazuje řádné hlášení |
| kontrolní hlášení po lhůtě | **Následné (§ 101f/2 - po lhůtě)** | vždy úplné, všechny údaje za období znovu |
| druhá oprava následného KH | **Následné/opravné** | úplné hlášení |
| výzva finančního úřadu k odpovědi | **Odpověď na výzvu** (dvě volby, viz níže) | podává se bez oddílů A, B a C |

### 41.5.1 Dodatečné přiznání k DPH

1. Ověřte, že původní přiznání za stejné období je v `Daně → EPO podání a archív` označené jako podané. Bez toho se dodatečné přiznání nedá spočítat.
2. Otevřete `Daně → DPH přiznání` a zvolte opravované období.
3. V poli **Typ podání** zvolte **Dodatečné (§ 141 - po lhůtě)**.
4. Vyplňte **Datum zjištění**, tedy kdy jste zjistili důvod opravy. Bez něj se náhled nespočítá. Datum nesmí předcházet konci opravovaného období ani být v budoucnosti.
5. Volitelně doplňte **Důvody podání**. Jdou do textové přílohy přiznání.
6. Zkontrolujte panel nad kartami. Ukazuje **Poslední známou daň** (stav před opravou) a **Rozdíl (ř. 66)**, přesně jak bude v podaném XML.
7. Klikněte na **Stáhnout XML** a podání dokončete podle [§ 41.6](#416-krok-za-krokem-podani-na-portalu-epo-a-dolozeni).

**Jak poznáte, že je hotovo:** Dodatečné podání je v archivu označené jako **Podáno**. Kdy je dodatečné přiznání nutné a jak se počítá druhé a další, vysvětlují [Podrobnosti](#41133-typ-podani-priznani-k-dph).

### 41.5.2 Následné kontrolní hlášení a odpověď na výzvu

1. Otevřete `Daně → Kontrolní hlášení` a zvolte opravované období.
2. V poli **Typ podání** zvolte **Následné (§ 101f/2 - po lhůtě)**.
3. Vyplňte **Datum zjištění**. Reagujete-li na výzvu správce daně, vyplňte i **Č.j. výzvy**.
4. Zkontrolujte sekce. Hlášení je úplné, obsahuje všechny údaje za období.
5. Klikněte na **Stáhnout XML** a podejte ho jako řádné.

Na doručenou výzvu máte jen 5 pracovních dnů, proto vždy vyplňte **Č.j. výzvy**.

Rychlá odpověď na výzvu se podává bez oddílů A, B a C. Zvolte **Odpověď na výzvu - nemám povinnost podat KH**, nebo **Odpověď na výzvu - potvrzuji správnost posledního KH**, a vyplňte **Č.j. výzvy**. Datum zjištění se u ní nezadává.

**Jak poznáte, že je hotovo:** Stažené XML je v archivu označené a po podání ho označíte jako podané.

## 41.6 Krok za krokem: podání na portálu EPO a doložení

1. Po kliknutí na **Stáhnout XML** otevřete `Daně → EPO podání a archív`. Záznam má stav **XML připraveno**.
2. Zkontrolujte výsledek lokální validace (**OK** nebo **Chyby**) a otevřete detail záznamu.
3. Klikněte na **Otevřít a podat v EPO**. Aplikace předá přesný archivovaný XML snapshot do předvyplněného formuláře EPO v novém okně. Nic se zatím samo neodešle.
4. V EPO spusťte obsahové kontroly, ověřte částky a potvrďte **Odeslat**.
5. Stáhněte odeslané XML a potvrzení (P7S). Přetáhněte je zpět do detailu podání. Aplikace je uloží do Dokumentů ve složce daného období a ověří dostupné technické kontroly.
6. Po kontrole doručenky klikněte na **Označit jako podané**.

**Jak poznáte, že je hotovo:** Záznam má stav **Podáno** (po nahrání potvrzení **Potvrzeno P7S**) a započítá se do **Doloženě podáno**.

> [!WARNING]
> Přijetí nebo odmítnutí sledujte na portálu. Rozhodujícím důkazem je potvrzení z EPO, stav v aplikaci nastavujete po kontrole doručenky sami.

## 41.7 Krok za krokem: OSS přiznání (OSSEI1)

OSS je samostatný režim s vlastní kapitolou [Režim OSS](45_OSS.md). Tady je jen postup sestavení podkladu.

1. Ověřte, že je zapnutý režim OSS v `Nastavení → Daně a účetnictví`. Bez něj se stránka v menu nezobrazí.
2. Otevřete `Daně → OSS přiznání` a zvolte čtvrtletí v poli **Období**.
3. Na záložce **Náhled** zkontrolujte **Základ daně**, **DPH z plnění**, **Opravy DPH**, **DPH celkem**, **Termín podání** a **Upozornění**. Odkaz **Zobrazit doklady s řádky k posouzení** otevře seznam faktur se sporným místem plnění.
4. Sledujte **Čerpání prahu 10 000 EUR** za rok.
5. Klikněte na **Stáhnout XML**. Stažení se archivuje se svým otiskem na záložce **Archiv podání**.
6. XML podejte v aplikaci MOSS/OSS na Daňovém portálu. Obecnou cestou EPO to nejde, viz oddíl [Kde se OSS přiznání podává](45_OSS.md#451014-kde-se-oss-priznani-podava) v kapitole OSS.
7. Podání doložte v `Daně → EPO podání a archív`.

**Jak poznáte, že je hotovo:** Záznam OSS v archivu je označený jako podaný. Záložka **Rekonciliace** ukazuje, že dnešní náhled odpovídá archivovanému podání.

## 41.8 Krok za krokem: koeficient krácení odpočtu (§ 76)

Potřebujete ho, jen když přijímáte faktury s volbou **Krácený (§76)** u pole Nárok na odpočet DPH (viz [Přijaté faktury](23_Prijate_faktury.md#23117-danova-uznatelnost-a-narok-na-odpocet)). Jde o společné vstupy pro plnění s nárokem na odpočet i plnění osvobozená bez nároku.

1. Otevřete `Nástroje → Koeficient krácení (§76)` a vpravo nahoře zvolte rok.
2. V části **Zálohový koeficient (§ 76/6)** zadejte do pole **Zálohový koeficient** celé procento 0 až 100 jako kvalifikovaný odhad a klikněte na **Uložit**.
3. Pod polem zkontrolujte **Skutečně uplatňovaný koeficient**. Nezadáte-li nic, převezme se vypořádací koeficient z minulého roku a zobrazí se štítek **přenesen z vypořádání minulého roku**.
4. Po skončení roku otevřete stránku znovu, zvolte rok a klikněte na **Provést roční vypořádání**. Potvrďte dotaz.
5. V části **Vypořádací koeficient (§ 76/7)** zkontrolujte **Čitatel (plnění s nárokem)**, **Jmenovatel (veškerá plnění)** a datum **Vypořádáno**.
6. Dorovnání se vykáže na ř. 53 v přiznání za poslední období roku. Zaúčtujte ho ručně do [Účetního deníku](52_Ucetni_denik.md).

**Jak poznáte, že je hotovo:** Za zálohový koeficient vidíte uložené procento, po vypořádání vidíte **Vypořádací koeficient** a datum. Nevypořádaný rok ukazuje **Roční vypořádání za tento rok zatím neproběhlo.**

> [!TIP]
> Vypořádání smí provést jen uživatel s oprávněním finalizovat výkazy (zpravidla administrátor). Je to vždy vědomý krok, náhled ani stažení přiznání koeficient automaticky neuloží.

## 41.9 Krok za krokem: oprava odpočtu u neuhrazených závazků (§ 74b)

Používáte ji jako dlužník: snižujete uplatněný odpočet u závazků déle po splatnosti.

1. Otevřete `Nástroje → Oprava odpočtu (§74b)`.
2. Vyberte měsíc a rok (**Za období**). Stránka ukáže **Rozhodný den**.
3. Klikněte na **Náhled**. Je to jen nezávazný výpočet, nic se nezapisuje.
4. Projděte tabulku: dodavatel, doklad, **Uplatněný odpočet**, **Neuhrazeno**, **Cílové snížení**, **Dosud korigováno** a **Delta**. Sloupec **Pohyb** ukazuje **Snížení** nebo **Obnova**.
5. Zkontrolujte souhrn **Snížení odpočtu**, **Obnova odpočtu** a **Čistý dopad na odpočet**.
6. Chcete-li korekce uložit, klikněte na **Zaevidovat období** a potvrďte.

**Jak poznáte, že je hotovo:** Zobrazí se hláška **Zaevidováno N korekcí za období do ledgeru.** Korekce se promítne do přiznání, kontrolního hlášení a Knihy DPH.

> [!WARNING]
> Zaevidování je vědomý zápis do daňové evidence, ne zaúčtování do deníku. Před ním ověřte splatnost, skutečné úhrady a původní nárok na odpočet. Zápis vyžaduje oprávnění finalizovat výkazy.

## 41.10 Krok za krokem: nedobytné pohledávky (§ 46)

Používáte ji jako věřitel: opravujete základ daně u pohledávky, kterou dlužník nezaplatil.

1. Otevřete `Nástroje → Nedobytné pohledávky (§46)`.
2. Nastavte **Po splatnosti alespoň** (počet dní) a **Ke dni**.
3. V části **Kandidáti na opravu** najděte pohledávku. Seznam je jen pracovní, nárok z něj neplyne.
4. Klikněte na **Zaevidovat opravu** u řádku.
5. V okně vyberte **Právní důvod** (insolvence, exekuce, smrt dlužníka, likvidace nebo malá pohledávka). Vyplňte **Datum doručení opravného dokladu dlužníkovi**, volitelně číslo opravného daňového dokladu a poznámku. Uložte.
6. Po úhradě dříve opravené pohledávky otevřete část **Obnovy po úhradě (§ 46e)**, zvolte měsíc a rok a klikněte na **Zaevidovat obnovy**.

**Jak poznáte, že je hotovo:** Zobrazí se hláška **Oprava zaevidována** s částkou a obdobím, případně **Zaevidováno N obnov.** Oprava se promítne do přiznání (ř. 1/2 záporně, ř. 33) a kontrolního hlášení (A.4) za období doručení.

> [!WARNING]
> Citlivý daňový výstup. Před podáním ověřte právní důvod a doručení opravného dokladu s daňovým poradcem.

## 41.11 Krok za krokem: opravy DPH (§ 43, § 79, § 79a)

1. Otevřete `Daně → Opravy DPH (§43, §79)`. Stránka má dvě záložky, vpravo nahoře zvolte rok.
2. Pro opravu výše daně použijte záložku **§ 43 - oprava výše daně**. Klikněte na **Zaevidovat opravu**.
3. V okně vyberte **Druh dokladu**, zadejte ID dokladu, **Rok plnění** a **Měsíc plnění** podle PŮVODNÍHO plnění, **Sazbu**, **Změnu základu** a **Změnu daně**, **Doručení dokladu** a **Důvod**. Uložte.
4. Pro odpočet při registraci nebo jeho snížení při zrušení registrace použijte záložku **§ 79 - registrace**. Klikněte na **Zaevidovat položku**.
5. V okně zadejte **Situaci** (**Registrace** nebo **Zrušení registrace**), **Popis majetku**, **Druh majetku** (**Zásoby** nebo **Dlouhodobý majetek**), **Pořízení**, **Rozhodný den**, **Daň na vstupu** a u dlouhodobého majetku **Lhůtu (roky)**. Uložte.

**Jak poznáte, že je hotovo:** Záznam je v tabulce na záložce a stránka ukazuje hlášku **Zaevidováno.** U § 79 ukazuje řádek **Celkem do ř. 45** součet, který se promítne do přiznání.

> [!WARNING]
> § 43 míří zpětně do období původního plnění a podává se dodatečné přiznání. Rozdíl od § 42 (dobropis) vysvětluje [§ 41.13.19](#411319-opravy-dph-43-79-a-79a-pravidla). Zápis vyžaduje oprávnění finalizovat výkazy.

## 41.12 Když něco nejde

<!-- cols: 32 30 38 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| EPO odmítne soubor s chybou o neúplné adrese | Chybí číslo popisné nebo orientační | V `Nastavení → Daně a účetnictví` vyplňte **Číslo popisné** a **Číslo orientační**. EPO chce ulici, popisné i orientační číslo zvlášť. |
| **Chybí kód finančního úřadu - XML nemusí projít validací EPO.** | V nastavení není kód úřadu | Vyplňte **Kód finančního úřadu** v `Nastavení → Daně a účetnictví`. Bez něj XML neprojde kontrolou. |
| **Chybí IČO tenanta.** nebo **Chybí DIČ tenanta.** | V identifikaci firmy chybí údaj | Doplňte IČO a DIČ v identifikaci firmy v nastavení. |
| **Tenant nebyl v průběhu období evidovaný jako plátce DPH - výkaz nemusí být relevantní.** | Plátcovství se posuzuje podle období výkazu, ne podle dneška | V bloku **Plátcovství DPH** zkontrolujte historii a doplňte nebo opravte řádek. Jste-li identifikovaná osoba, nechte **Plátce DPH** vypnuté a zaškrtněte **Identifikovaná osoba (§ 6g-6l ZDPH)**, přiznání se pak tvoří jako přiznání identifikované osoby. |
| Částky v tabulkách přiznání nesedí s fakturami | Řádky faktur nemají správnou **Klasifikaci DPH** | V editoru faktury zkontrolujte pole **Klasifikace DPH** na řádcích. Kódy a automatika jsou v [§ 41.13.9](#41139-klasifikacni-kody-dph) a [§ 41.13.10](#411310-automaticke-prirazeni-klasifikace). |
| Prázdné tabulky a hláška, že musíte faktury označit klasifikací | V období nejsou doklady s klasifikací | Označte řádky faktur klasifikací DPH. Starším importovaným dokladům ji doplňte ručně v editoru. |
| Přiznání je čtvrtletní, ale aplikace počítá měsíčně | V nastavení je jiná perioda | V `Nastavení → Daně a účetnictví` změňte **Perioda DPH přiznání** na kvartální, pak na stránce přiznání zvolte **Kvartálně** a čtvrtletí. |
| Nevím kód úřadu a pracoviště | - | Podívejte se na poslední přiznání nahrané na EPO (VetaD a VetaP), zavolejte na svůj finanční úřad, nebo použijte [seznam územních pracovišť](https://www.financnisprava.cz/cs/financni-sprava/organy-financni-spravy/uzemni-pracoviste). |
| OKEČ vychází `631000`, ale činnost je jiná | Pole CZ-NACE je prázdné, použije se náhradní hodnota | Vyplňte **CZ-NACE klasifikaci** podle živnostenského listu nebo ARES. Aplikace odstraní předponu a doplní na 6 číslic. |
| Červený rámeček **Přiznání nesedí na kontrolní/souhrnné hlášení nebo účet 343** | Přiznání se rozchází s KH, SH nebo obratem účtu 343 | Opravte podklad (doplňte DIČ, opravte klasifikaci, zaúčtujte chybějící doklad) a načtěte náhled znovu. Viz [§ 41.13.5](#41135-krizova-kontrola-s-kh-sh-a-uctem-343). |
| **Doklady se samovyměřením čekají na schválení** | Přijaté doklady jsou ve schvalování nebo zamítnuté, proto v podání chybí | Doklady před podáním schvalte, nebo stornujte. Daň ze samovyměření patří do období DUZP a nejde ji přesunout. |
| **Nevystavené daňové doklady k přijatým zálohám v období** | Koncept DDKP nebo finálního dokladu z proformy | Doklady dokončte před podáním DPH. Daňová povinnost vzniká přijetím úplaty. |
| **Dodatečné přiznání vyžaduje datum zjištění důvodů (§ 141 daňového řádu).** | Chybí **Datum zjištění** | Vyplňte pole **Datum zjištění**, bez něj se rozdíl proti poslední známé dani nespočítá. |
| **Datum zjištění důvodů nemůže být v budoucnosti.** nebo hláška, že datum předchází konci období | Datum je mimo dovolený rozsah | Zadejte datum ode dne po skončení opravovaného období do dneška. |
| **Pro dané období neexistuje dřívější řádné/opravné přiznání** | Za období není v archivu podané řádné ani opravné přiznání, nemá se čeho rozdíl počítat | Nejdřív podejte řádné (nebo opravné) přiznání a označte ho v archivu jako podané, pak teprve dodatečné. |
| **Opravné dodatečné přiznání (druh E) zatím není podporováno** | Oprava už podaného dodatečného přiznání nahrazuje předchozí dodatečné a poslední známou daň nejde bezpečně dopočítat | Volba se v poli **Typ podání** záměrně nenabízí. Přiznání sestavte ručně s daňovým poradcem. |
| Faktura nemá klasifikační kód | Sazba na řádku nemá výchozí kód v číselníku | V `Systém → Sazby a číselníky`, na záložce **Klasifikace DPH**, přidejte kód, nebo ho zvolte ručně v editoru. |
| DIČ klienta není ve formátu CZxxxxxxxx | KH potřebuje DIČ bez předpony CZ, aplikace ho ořízne sama | Nemá-li klient DIČ, doklad jde do sumace A.5 (B.3) bez ohledu na částku. Má-li doklad patřit do A.4 (B.2), doplňte protistraně DIČ. |
| Doklad s **Krácený §76** nejde zaúčtovat ani zahrnout do přiznání | Pro rok není nastavený zálohový koeficient | Nastavte ho podle [§ 41.8](#418-krok-za-krokem-koeficient-kraceni-odpoctu-76). |
| Doklad s reverse charge a **Krácený (§76)** zároveň se odmítne zaúčtovat | Kombinace není podporovaná (ř. 43 nemá krácený protějšek) | Doklad zaúčtujte a vykažte ručně. |
| Tlačítko **Stáhnout XML** chybí | Chybí oprávnění exportovat výkazy | Požádejte správce firmy o oprávnění. |
| V přehledu DPH je štítek **Neaktuální** | V už vyrovnaném období se změnil doklad | V panelu **Interní doklad zúčtování DPH** klikněte na **Přepočítat zúčtování**. Do uzavřeného nebo zamčeného období se doklad nepřepíše, jen se ohlásí nález. |
| Přiznání identifikované osoby neobsahuje tuzemské řádky nebo odpočet | Je to záměr režimu | Viz [§ 41.13.7](#41137-jak-se-dphdp3-sestavuje). |

## 41.13 Podrobnosti a pravidla

### 41.13.1 Pole EPO a VetaP

Tato část mapuje pole z `Nastavení → Daně a účetnictví`, box **Daňové nastavení (EPO výkazy DPH/KH)**, na atributy v EPO XML (DPHDP3 a DPHKH1). Vyplňte údaje potřebné pro právní formu a podání; některá pole jsou volitelná.

#### Identifikace finančního úřadu

<!-- cols: 26 14 60 -->
| Pole v UI | XML atribut | Popis a kde zjistit |
|---|---|---|
| **Kód finančního úřadu** | `c_ufo` | Číselný kód finančního úřadu, např. `451` Finanční úřad pro hlavní město Prahu, `461` Finanční úřad pro Jihomoravský kraj, `13` Specializovaný finanční úřad. Najdete ho na posledním podaném přiznání nebo v EPO. |
| **Kód územního pracoviště (ÚzP)** | `c_pracufo` | Konkrétní pracoviště v rámci úřadu, např. `3203` pracoviště Brno III. Volitelné, ale EPO ho někdy vyžaduje. |
| **CZ-NACE klasifikace** | `c_okec` | Hlavní podnikatelská činnost, např. `631000` (IT poradenství). Najdete ji na živnostenském listě nebo v ARES. Při prázdném poli se použije `631000`. Pole nabízí jen kódy platné k dnešku, číselník EPO je od 1. 1. 2026 na NACE rev. 2.1. |

#### Typ plátce a perioda

<!-- cols: 24 18 58 -->
| Pole v UI | XML atribut | Hodnoty a použití |
|---|---|---|
| **Typ poplatníka** | `typ_ds` ve VetaP | `F` (fyzická osoba, OSVČ) nebo `P` (právnická osoba, s.r.o.). Podle právní formy. |
| **Plátce DPH** a **Identifikovaná osoba (§ 6g-6l ZDPH)** | `typ_platce` ve VetaD | `P` (plátce) nebo `I` (identifikovaná osoba). `I` se nastaví automaticky, když je firma k rozhodnému datu v historii plátcovství vedená jako identifikovaná osoba (viz [§ 38.1.4](40_Fakturujeme.md#4094-identifikovana-osoba-6g-6l-zdph)). |
| **Perioda DPH přiznání** | `mesic` nebo `ctvrt` | Měsíc, nebo čtvrtletí podle nastavené periody. |

#### Sídlo a adresa

EPO rozděluje uliční adresu na tři samostatné atributy (`ulice`, `c_pop`, `c_orient`). MyÚčto je drží v samostatných polích.

<!-- cols: 30 18 52 -->
| Pole v UI | XML atribut | Popis |
|---|---|---|
| **Ulice** | `ulice` | Název ulice bez čísla, např. `Vodičkova`. |
| **Číslo popisné** | `c_pop` | Popisné číslo budovy, např. `1104`. Necháte-li prázdné, vyparsuje se z pole Ulice. |
| **Číslo orientační** | `c_orient` | Orientační číslo, např. `36`. |
| **Město** | `naz_obce` | Posílá se beze změny, velikost písmen si normalizuje EPO. |
| **PSČ** | `psc` | Bez mezer, aplikace je odstraní. |
| Země | `stat` | Výchozí `CZE` (Česká republika). |

> [!WARNING]
> U OSVČ vyžaduje EPO adresu sídla podnikání, ne trvalého bydliště, pokud se liší. Najdete ji v živnostenském rejstříku nebo v ARES jako "Místo podnikání".

#### Osobní údaje (jen fyzická osoba, OSVČ)

Vyplňte je v `Nastavení → Daně a účetnictví` v části **Osobní údaje podnikatele**. Pole **Titul** je nepovinné a může obsahovat více titulů, například `Ing. Ph.D.`; limit je 10 znaků. EPO údaje o podnikateli používá v přiznání k DPH, kontrolním i souhrnném hlášení. Stejné údaje se používají také v přiznání DPFO a ve mzdových EPO formulářích, které titul podporují.

<!-- cols: 26 18 56 -->
| Pole v UI | XML atribut | Popis |
|---|---|---|
| **Titul** | `titul` | Titul nebo kombinace titulů, například `Ing.`, `Ph.D.` či `Ing. Ph.D.`; nepovinné, nejvýše 10 znaků. |
| Jméno | `jmeno` | Křestní jméno plátce. |
| Příjmení | `prijmeni` | Příjmení plátce. |

Právnické osoby tato pole nevyplňují. Místo nich se použije `zkrobchjm` z firmy.

#### Oprávněná osoba k podpisu (právnická osoba)

V `Nastavení → Daně a účetnictví` vyplňte část **Oprávněná osoba (jednatel / podpisující)**. Pole `opr_*` identifikují fyzickou osobu, která je u právnické osoby oprávněná přiznání podepsat (typicky jednatel, předseda představenstva). Pro právnickou osobu jsou povinná.

<!-- cols: 34 18 48 -->
| Pole v UI (blok **Oprávněná osoba (jednatel / podpisující)**) | XML atribut | Popis |
|---|---|---|
| **Jméno** | `opr_jmeno` | Křestní jméno jednatele nebo podepisujícího. |
| **Příjmení** | `opr_prijmeni` | Příjmení. |
| **Postavení (např. jednatel)** | `opr_postaveni` | Funkce, typicky `jednatel`, `majitel`, `předseda představenstva`. |

U OSVČ se údaje o podnikateli zadávají v části **Osobní údaje podnikatele**. Nevyplněné jméno a příjmení se odvodí z názvu (včetně odstranění akademických titulů).

#### Zastoupení a podepisující osoba

Podává-li za firmu podání někdo jiný než jednatel nebo podnikatel sám, zapište ho v `Nastavení → Daně a účetnictví → Zastoupení a podepisující osoba`. Zástupce se pak vyplní jako podepisující osoba (`zast_*`) do přiznání DPH, kontrolního a souhrnného hlášení i do přiznání k dani z příjmů. Zastoupení se eviduje v čase a ukládá se hned, bez tlačítka Uložit. Podání dostane zástupce platného ke dni, kdy XML vytváříte, finalizované přiznání k dani z příjmů zástupce platného ke dni finalizace.

<!-- cols: 34 22 44 -->
| Pole | XML atribut | Popis |
|---|---|---|
| **Typ zástupce** | `zast_typ` | Fyzická (F), nebo právnická osoba (P). |
| **Kód podepisující osoby** | `zast_kod` | Podle číselníku EPO, viz tabulka níže. |
| **Jméno a příjmení** (F) | `zast_jmeno`, `zast_prijmeni` | Zástupce fyzická osoba. |
| **Název a IČO** (P) | `zast_nazev`, `zast_ic` | Zastupující právnická osoba, IČO je povinné. |
| **Evidenční číslo** | `zast_ev_cislo` | Číslo v seznamu KDP ČR nebo ČAK, povinné u kódu 4b. |
| **Datum narození** (F) | `zast_dat_nar` | U fyzické osoby bez evidenčního čísla. |
| **Osoba podepisující za zástupce** (P) | `opr_*` | Fyzická osoba, která za zastupující právnickou osobu podepisuje. |

<!-- cols: 14 43 43 -->
| Kód | Fyzická osoba | Právnická osoba |
|---|---|---|
| 1 | zákonný zástupce nebo opatrovník | zákonný zástupce nebo opatrovník |
| 2 | ustanovený zástupce | ustanovený zástupce |
| 3 | společný zástupce, společný zmocněnec | společný zástupce, společný zmocněnec |
| 4a | obecný zmocněnec | obecný zmocněnec |
| 4b | daňový poradce nebo advokát | - |
| 4c | - | právnická osoba vykonávající daňové poradenství |
| 5a / 5b | osoba spravující pozůstalost / její zástupce | osoba spravující pozůstalost / její zástupce |
| 6a / 6b | dědic po skončení řízení / jeho zástupce | dědic po skončení řízení / jeho zástupce |
| 7a | - | právní nástupce právnické osoby |
| 7b | zástupce právního nástupce právnické osoby | zástupce právního nástupce právnické osoby |

Účetní kancelář, která podává na plnou moc a nemá osvědčení daňového poradce, je **obecný zmocněnec (4a)**. Jen u kódů 4b a 4c se v přiznání k dani z příjmů vyplní "podává daňový poradce" a jen u nich se lhůta pro podání prodlužuje podle § 136 odst. 2 daňového řádu.

Je-li podepisující osobou zástupce fyzická osoba, jméno oprávněné osoby (`opr_*`) se do podání nevyplňuje. U zastupující právnické osoby se do `opr_*` vyplní osoba, která za ni podepisuje.

#### Sestavitel přiznání

Pole sestavitele jsou relevantní jen tehdy, když přiznání za vás podává jiná osoba (účetní, daňový poradce). Podáváte-li sami, nechte je prázdná, použijí se vaše údaje (jméno, příjmení, telefon).

<!-- cols: 36 20 44 -->
| Pole v UI (blok **Sestavitel přiznání (účetní)**) | XML atribut | Popis |
|---|---|---|
| **Jméno** | `sest_jmeno` | Křestní jméno sestavitele. |
| **Příjmení** | `sest_prijmeni` | Příjmení sestavitele. |
| **Telefon** | `sest_telef` | Ve formátu `+420XXXXXXXXX`. |
| **E-mail** | (jen interní log) | Pro audit, EPO XML ho neukládá. |
| **Funkce** | (jen interní log) | Volný text, např. `účetní`, `daňový poradce`. |

Necháte-li příjmení prázdné a do jména napíšete celé jméno ("Jan Novák"), rozdělí se do XML podle první mezery. Pro spolehlivost vyplňte obě pole zvlášť.

#### Kontaktní údaje pro podání

<!-- cols: 30 20 50 -->
| Pole v UI | XML atribut | Popis |
|---|---|---|
| **E-mail** | `email` | Kontakt pro finanční úřad. |
| **Telefon** | `c_telef` | Ve formátu `+420XXXXXXXXX`. |

### 41.13.2 Podání na portál EPO a stavy záznamu

Stažením XML vznikne v `Daně → EPO podání a archív` záznam se stavem **XML připraveno**. Ten neznamená, že soubor odešel správci daně. Aplikace rozlišuje rozpracované, vygenerované, stažené a odeslané podání. Teprve explicitní označení jako **podané** může sloužit jako základ pro dodatečné přiznání a uzamknout skončené období DPH a KH. Po nahrání podepsaného potvrzení aplikace zobrazí dostupné technické kontroly. Stav podání nastavujete ručně po kontrole doručenky. Přijetí nebo odmítnutí je nutné sledovat podle portálu. Rozhodujícím důkazem zůstává potvrzení z EPO.

Před otevřením EPO můžete XML volitelně zkontrolovat v textovém editoru:

- **VetaD:** ověřte `rok`, `mesic` nebo `ctvrt`, `typ_platce`, `c_okec`, `d_poddp` (datum podání je dnes).
- **VetaP:** ověřte `dic`, `c_ufo`, `c_pracufo`, identifikační údaje a adresu.
- **Veta1 a Veta4:** ověřte součty `obrat23` a `dan23` (výstup), `pln23` a `odp_tuz23_nar` (vstup) proti seznamu faktur za období.
- **Veta6:** `dano_da` (daň k odvodu) nebo `dano_no` (nadměrný odpočet).

> [!TIP]
> Struktura XML musí zůstat zachovaná, ale hodnoty atributů můžete v editoru upravit. Užitečné pro rychlou opravu bez přepočtu. Takto upravený soubor ale aplikace neporovnává, viz [§ 41.13.21](#411321-co-kontrola-podani-neumi).

### 41.13.3 Typ podání přiznání k DPH

<!-- cols: 24 36 40 -->
| Typ | Kdy použít | Jak se počítá |
|---|---|---|
| **Řádné** (výchozí) | Standardní podání v řádné lhůtě | Plný přepočet za období |
| **Opravné** (§ 138 daňového řádu) | Nahrazuje už podané řádné přiznání, dokud za dané období neuplynula lhůta pro podání | Počítá se znovu celé (ne rozdíl), jen s jiným typem podání v XML |
| **Dodatečné** (§ 141 daňového řádu) | Podání po lhůtě, kdy se u už podané daně za období musí něco opravit | Vykáže jen ROZDÍL oproti poslední známé dani, ne absolutní částky |

Po výběru **Dodatečné** se zobrazí povinné pole **Datum zjištění** (kdy jste zjistili důvod opravy). Bez jeho vyplnění se náhled nespočítá.

> [!WARNING]
> Dodatečné přiznání nevykazuje absolutní částky, ale jen rozdíl proti poslednímu archivnímu XML, které bylo v systému explicitně označeno jako odeslané (řádné, případně opravné). Pouhé stažení XML základnu nevytvoří. Není to volba aplikace, ale zákonný požadavek (§ 141 daňového řádu), proto se datum zjištění vyžaduje. Pokud jste za dané období nepodali žádné řádné ani opravné přiznání, dodatečné přiznání nejde spočítat vůbec, protože chybí základna.

Pokud jste za dané období už podali jedno dodatečné přiznání a zjistíte, že je potřeba opravit ještě jednou, druhé (a každé další) dodatečné přiznání počítá rozdíl kumulativně, tedy proti stavu po předchozím dodatečném přiznání, ne proti původnímu řádnému. Stejná částka se tak nevykáže podruhé.

Volba **Dodatečné/opravné** (oprava už podaného dodatečného přiznání) se v poli **Typ podání** vůbec nenabízí. Je to právně složitější případ: takové podání předchozí dodatečné přiznání nahrazuje, nesčítá se s ním, a poslední známou daň by nebylo možné bezpečně dopočítat bez rizika, že se stejná částka vykáže dvakrát. Přiznání sestavte ručně ve spolupráci s daňovým poradcem.

### 41.13.4 Fronta Doklady změněné po podání

Jakmile bylo přiznání za dané období aspoň jednou v archivu označeno jako odeslané, může se na stránce objevit žlutá sekce **Doklady změněné po podání**. Obsahuje doklady (vydané i přijaté faktury, daňové pokladní doklady), které svým DPH-rozhodným datem (viz [§ 41.13.7](#41137-jak-se-dphdp3-sestavuje)) spadají do naposledy podaného období, ale po posledním podání přibyly nebo se změnilo něco, co ovlivňuje DPH: částka, DUZP, základ nebo daň na řádcích přiznání. Snapshot podání zachytí také doklad, který byl po podání stornován nebo mu bylo DUZP přesunuto mimo období. Úhrada, upomínka ani jiná úprava bez dopadu na DPH doklad do fronty nezařadí. U každého dokladu vidíte jeho číslo, částku a datum poslední změny. U starších podání bez snapshotu aplikace zobrazí upozornění, že je potřeba porovnat podání s knihou DPH ručně.

Sekce se zobrazí jen tehdy, když za dané období už bylo přiznání v archivu označeno jako odeslané (řádné, opravné nebo dodatečné). U období, které ještě podané nebylo, fronta nedává smysl.

Doklady v podaném období (přiznání k DPH nebo kontrolní hlášení označené jako podané) jsou chráněné i proti změně. U vydané faktury mění částky, data a položky jen správce firmy. U přijaté faktury platí totéž pro každou změnu, která se promítne do evidence DPH: vynucenou úpravu, členění DPH položek i **Změnit DPH zařazení**; rozhoduje období odpočtu dokladu. Správce musí změnu DPH zařazení v podaném období navíc výslovně potvrdit. Každou takovou změnu je potřeba vykázat opravným nebo dodatečným tvrzením (viz [§ 41.5](#415-krok-za-krokem-oprava-uz-podaneho-priznani-nebo-hlaseni)).

> [!TIP]
> Fronta je jen podklad pro rozhodnutí, nic sama nevynucuje. Pokud se v ní doklad objeví, zvažte, zda je rozdíl významný natolik, že je potřeba podat dodatečné přiznání (viz [§ 41.5](#415-krok-za-krokem-oprava-uz-podaneho-priznani-nebo-hlaseni)), nebo zda stačí ho promítnout až do dalšího řádného období.

Přijatý doklad, který čeká na schválení nebo byl zamítnut, zůstává konceptem, a proto v přiznání ani v kontrolním hlášení není. Náhled přiznání i kontrolního hlášení takové doklady vyjmenuje s odkazem na detail, pokud by po schválení patřily do zvoleného období:

- **Doklady se samovyměřením** (přenesení daňové povinnosti, pořízení z EU, přijetí služby ze zahraničí, dovoz) jsou v červeném rámečku. Daň ze samovyměření patří do období DUZP a nedá se přesunout do pozdějšího období, proto stažení XML vyžaduje potvrzení stejně jako ostatní blokující rozdíly. Doklady před podáním schvalte, nebo stornujte.
- **Ostatní doklady** jsou jen informace: odpočet z nich můžete uplatnit později.

Křížová kontrola současně neblokujícím upozorněním vyjmenuje koncepty DDKP a finálních dokladů z proformy i přijaté platby proformy, ke kterým daňový doklad ještě nevznikl. Před podáním je dokončete nebo účetně ověřte, protože daňová povinnost vzniká přijetím úplaty. U kontrolního hlášení se doklady čekající na schválení vypisují stejně, rychlá odpověď na výzvu oddíly A a B nemá, proto se u ní nekontrolují.

### 41.13.5 Křížová kontrola s KH, SH a účtem 343

Při každém načtení náhledu aplikace automaticky porovná chystané přiznání se čtyřmi zdroji. Finanční úřad si první tři páruje strojově, takže jakýkoli nesoulad typicky znamená výzvu nebo kontrolu.

<!-- cols: 36 64 -->
| Kontrola | Co se porovnává |
|---|---|
| DPHDP3 ř. 1+2 a KH | Tuzemská zdanitelná plnění na výstupu proti sekci **A.4 + A.5** kontrolního hlášení |
| DPHDP3 ř. 10+11 a KH | Tuzemský přijatý reverse charge proti sekci **B.1** kontrolního hlášení |
| DPHDP3 ř. 20+21 a SH | Dodání zboží a služeb do jiného členského státu proti souhrnnému hlášení |
| Obrat účtu 343 a vlastní daň | Zaúčtovaný obrat účtu **343** (podvojné účetnictví) proti vlastní dani nebo nadměrnému odpočtu z přiznání |

Pokud vše sedí, na stránce se nic nezobrazí. Jinak se nad kartami objeví červená sekce **Přiznání nesedí na kontrolní/souhrnné hlášení nebo účet 343**. Obsahuje:

- popis, čeho se rozdíl týká (např. "DPHDP3 ř.1+2 a KH A.4 + A.5"),
- konkrétní částku z obou stran a rozdíl v Kč,
- je-li možné rozdíl přiřadit ke konkrétním dokladům, seznam dokladů (číslo dokladu a částka na obou stranách). Kliknutím na číslo otevřete detail vedle přiznání; **Shift+klik** jej otevře v pravém panelu. Ruční účetní zápis vede na konkrétní zápis v deníku. Pokud byl původní převzatý doklad smazán, historický rozdíl rovněž odkazuje na existující účetní zápis. Odkaz se zobrazuje podle oprávnění ke čtení příslušné agendy. U rozdílu proti souhrnnému hlášení je i vysvětlení pravděpodobné příčiny (chybějící DIČ odběratele, plnění zařazené jako EU, ale na tuzemské nebo ne-EU zemi apod.).

U kontroly účtu 343 rozpis navíc u každého rozdílového dokladu vysvětlí, zda jde o časový posun odpočtu podle § 73 ZDPH, odlišnou částku, chybějící řádek 343, nebo zápis bez protějšku v přiznání. Typický časový posun vznikne, když je předpis zaúčtovaný k DUZP na konci měsíce, ale přijatý doklad dorazí až v následujícím měsíci. Pokud celý rozdíl vysvětlují pouze časové posuny, zobrazí se neutrální informace **Rozdíl obratu 343 je vysvětlen časovým posunem podle § 73 ZDPH** s čísly dokladů a přiznání můžete stáhnout bez potvrzování nesouladu. Nevysvětlený zbytek nad toleranci zůstává červený a blokující.

U dokladů převzatých z jiného účetního programu kontrola přiřadí i samostatný zápis DPH, pokud je převzatý zápis jednoznačně navázaný na doklad. Obyčejná ruční vazba mezi zápisem a dokladem sama o sobě původ DPH neurčuje. Doklad ponechaný po převodu jako koncept do přiznání nevstupuje; jeho případné zaúčtování na účtu 343 proto zůstává rozdílem ke kontrole.

> [!WARNING]
> Tlačítko **Stáhnout XML** se při nalezeném rozdílu úplně nezablokuje, ale vyžádá potvrzení: zobrazí se dialog, že se přiznání rozchází s kontrolním nebo souhrnným hlášením, nebo s obratem účtu 343, a že finanční úřad páruje podání strojově. Teprve po potvrzení se XML stáhne. Tahle vědomá volba se spolu s celým rozpisem rozdílu zapíše do auditní stopy.

Kontrola obratu účtu 343 se automaticky přeskočí (zobrazí se jen informativní šedá poznámka, nic neblokuje), pokud v období existují ještě nezaúčtované doklady DPH. Rozdíl pak neznamená chybu v přiznání, jen že se zatím nezaúčtovalo vše. Jakmile doklady zaúčtujete, kontrola při dalším načtení proběhne znovu.

Kontrola se počítá nad stejnými reálnými výkazy, jaké se skutečně podávají (tentýž účetní deník, tytéž postupy jako u KH a SH), takže nikdy neukáže jiný rozdíl, než jaký by nastal při skutečném podání. Pokud se sekce objeví, opravte podklad (doplňte DIČ, opravte klasifikaci, zaúčtujte chybějící doklad) a znovu načtěte náhled. Po opravě sekce zmizí.

### 41.13.6 Převod DPH na zúčtovací účet

Po skončení zdaňovacího období vzniká interní doklad **převod DPH**, který přesune výstupní daň z `343.200` a vstupní daň z `343.100` na zúčtovací účet `343.900`. Po něm drží `343.900` přesně tu částku, kterou finančnímu úřadu dlužíte nebo kterou od něj čekáte, a tu pak uzavře platba z banky.

Doklad se řídí přiznáním, ne kalendářem. Založí se a přepočítá ve chvíli, kdy přiznání podáte, takže hlavní kniha ukazuje přesně to, co odešlo na úřad. Dodatečné i opravné přiznání ho přepočítají znovu. Sestavení návrhu přiznání už existující doklad osvěží, ale nový nezaloží, protože návrh není podání. Řeší to situaci, kdy doklad za dané období dorazí až po termínu: změní přiznání, a s ním i převod.

Na stránce `Daně → DPH přiznání` je panel **Interní doklad zúčtování DPH** (jen u firem v podvojném účetnictví a s oprávněním číst účetnictví). Ukazuje **Daň na výstupu**, **Daň na vstupu** a **Zůstatek k odvodu** a stav: **Sedí**, **Neaktuální** (do období po zúčtování přibyl nebo se změnil doklad), **Chybí doklad** nebo **Netýká se**. Tlačítko **Zaúčtovat zúčtování** (nebo **Přepočítat zúčtování**) je ruční cesta pro období, za která se přiznání v aplikaci nepodává, a pro nápravu po opravě zpětného dokladu. Před zápisem do deníku uvidíte náhled s výstupní daní, vstupní daní a výsledným zůstatkem. Odkaz **Zobrazit v deníku** otevře zápis.

Rozdíly do 1 Kč se ignorují: roky zaúčtované ručně v celých korunách by jinak hlásily nález trvale. Do uzavřeného nebo zamčeného období se doklad nikdy nepřepíše ani nesmaže, jen se ohlásí nález. Aktualizace jinak probíhá přepisem původního dokladu, ne stornem.

### 41.13.7 Jak se DPHDP3 sestavuje

Tato část přesně popisuje pravidla, podle kterých se přiznání sestavuje. Hodí se ke kontrole proti seznamu faktur i pro účetní.

#### Zdroje dat a granularita

- **DPH na výstupu (ř. 1-26)** se počítá z položek vystavených faktur a z řádků DPH zaúčtovaných příjmových pokladních daňových dokladů.
- **DPH na vstupu a nárok na odpočet (ř. 40-47)** se počítá z položek přijatých faktur, jejich řádkových alokací a řádků DPH zaúčtovaných výdajových pokladních dokladů.
- **Samovyměřená daň** u reverse charge a pořízení z EU se objevuje na obou stranách (výstup ř. 3-13 a odpočet ř. 43).
- Sčítá se po řádcích faktur, ne po fakturách. Důvodem je kurz cizí měny a možnost klasifikace po řádcích.

Tato řádková evidence je společná pro daňovou evidenci i podvojné účetnictví. Změna účetního režimu proto sama nemění výsledek DPHDP3, KH ani SH. Datum úhrady ovlivňuje daň z příjmů v daňové evidenci, nikoli období DPH.

#### Které doklady se zahrnou

<!-- cols: 20 80 -->
| Filtr | Pravidlo |
|---|---|
| **Období** | **Vystavené** doklady se řadí podle DUZP (jinak podle data vystavení), protože daň na výstupu vzniká k datu plnění. **Přijaté tuzemské** se řadí podle nejpozdějšího ze tří dat: DUZP, datum vystavení a **datum přijetí**, pokud ho zadal uživatel. Nárok na odpočet nelze uplatnit dříve, než plátce doklad fyzicky drží (§ 73 odst. 1 písm. a ZDPH). Typicky se to projeví u dokladu se zpětným DUZP, který dorazil později: spadne do měsíce, kdy jste ho fyzicky nebo e-mailem dostali. U **importovaných** dokladů (AI extrakce, ISDOC, iDoklad a Fakturoid, bankovní avízo, scan inbox) se datum přijetí do řazení nepočítá, protože import ho plní datem zpracování, ne skutečným přijetím. Použije se pozdější z DUZP a data vystavení. Rozhoduje skutečná změna pole, ne to, že doklad někdo otevřel a uložil: přeuložení vytěženého dokladu beze změny data přijetí ho ponechá importním. **Přijaté zahraniční reverse charge** (pořízení zboží z JČS, služby z EU a ze třetích zemí, dovoz) se řadí podle DUZP. Povinnost přiznat daň (ř. 3-13) vzniká k DUZP bez ohledu na to, kdy doklad dorazil (§ 25 odst. 1, § 24), a pozdní doklad neblokuje ani zrcadlový odpočet ř. 43 (§ 73 odst. 1 písm. b, nárok lze prokázat jiným způsobem). Tuzemský reverse charge (kód 5) zůstává konzervativně na pozdějším z dat. Zobrazené datum plnění dál nese skutečné DUZP, mění se jen příslušnost k období. Doklad bez vyplněného DUZP nevypadne. |
| **Stav** | Vylučují se koncepty a stornované doklady. U vystavených navíc zálohové faktury (proforma), protože zálohová faktura není daňový doklad. |
| **Klasifikace** | Řádek se zařadí podle klasifikace DPH (přednost má volba na řádku, pak na hlavičce, pak automatika podle sazby, reverse charge a směru). Řádek bez výsledného kódu se do přiznání nedostane. |

#### Přepočet měny

Základ i daň se vždy převedou na CZK kurzem faktury. U faktur v CZK je kurz 1. Chybějící kurz u cizoměnového daňového plnění je chyba podkladu. Evidence drží haléře, ale jednotlivé atributy a řádky DPHDP3 se v XML zaokrouhlují na celé Kč běžným matematickým zaokrouhlením. Dodatečné přiznání počítá rozdíl až mezi takto zaokrouhlenými hodnotami nové a poslední odeslané verze, proto prostý rozdíl haléřových součtů nemusí být totožný.

#### Mapování na řádky přiznání

<!-- cols: 16 62 22 -->
| Řádek | Co obsahuje | Typický kód |
|---|---|---|
| **1 / 2** | Tuzemská zdanitelná plnění na výstupu 21 % / 12 % | 1 / 2 |
| **3 / 4** | Pořízení zboží z JČS (samovyměření) 21 % / 12 % | 23 |
| **5 / 6** | Přijetí služby z EU | 24e |
| **7 / 8** | Dovoz zboží ze 3. země | 25 |
| **10 / 11** | Tuzemský reverse charge (příjemce) | 5 |
| **12 / 13** | Přijetí služby ze 3. země | 24 |
| **20-26** (oddíl C) | Dodání zboží do EU, vývoz, služby do JČS: osvobozená plnění s nárokem na odpočet, jen základ bez daně | 20 / 22 / 26 |
| **40 / 41** | Nárok na odpočet, tuzemsko 21 % / 12 %. Doklad s **Krácený §76** míří na tentýž řádek, jen do sloupce "Krácený odpočet", viz [§ 41.13.8](#41138-kraceny-odpocet-76-koeficient). | 40 / 41 |
| **43** | Nárok na odpočet u samovyměřené daně (zrcadlo ř. 3-13) | druhotný řádek |
| **47** | Hodnota pořízeného dlouhodobého majetku, doplňující údaj k ř. 40-45 | příznak majetek |
| **52 / 53** | Krácení odpočtu koeficientem (§ 76): zálohové (52, každé období) a roční vypořádací dorovnání (53, jen poslední období roku) | koeficient zálohový / vypořádací |

Oddíl C (ř. 20-26) se generuje do elementu `Veta2`: dodání do EU (`dod_zb`), vývoz (`pln_vyvoz`), služby do JČS (`pln_sluzby`) a další. Jde o osvobozená plnění, na DPHDP3 se uvádí jen základ (žádná daň), ale ovlivňují vypořádací koeficient (ř. 51-53).

#### Identifikovaná osoba

Identifikovaná osoba vyplňuje z celé tabulky jen **ř. 3-6 a 12-13**. Zrcadlový odpočet ř. 43 a navázaný ř. 47 se vyřadí potichu: to je smysl režimu, identifikovaná osoba nárok na odpočet nemá a samovyměřená daň jí zůstává jako skutečný výdaj (daň se reálně platí, ř. 64). Ř. 7/8 (dovoz, daň vybírá celní úřad) a ř. 10/11 (tuzemský reverse charge § 92a, jen mezi plátci) identifikovaná osoba věcně nemá. Cokoli dalšího, co z klasifikací vyjde (tuzemské ř. 1/2, oddíl C, odpočty ř. 40+), se vynechá s upozorněním v náhledu, ať je vidět, co a proč vypadlo. Kvartální volba se ignoruje, přiznání se podává vždy měsíčně a jen za měsíce, kdy povinnost vznikla. Kontrolní hlášení identifikovaná osoba nepodává, služby do EU vykazuje v souhrnném hlášení. Podrobnosti viz [§ 38.1.4](40_Fakturujeme.md#4094-identifikovana-osoba-6g-6l-zdph).

#### Samovyměření daně u reverse charge

U reverse charge (faktura s příznakem reverse charge nebo klasifikační kód s tímto příznakem, kódy 5 a 23) dodavatel fakturuje bez DPH. Aplikace daň dopočítá ze základu: daň v CZK = základ v CZK × sazba / 100. Tatáž částka se uvede dvakrát:

- na výstupu (ř. 3 u zboží z EU, ř. 10 u tuzemského RC, ř. 5 a 12 u služeb),
- na vstupu jako odpočet na ř. 43.

Čistý dopad na vlastní daň je tedy nulový (daň = odpočet), pokud máte plný nárok.

#### Vlastní daň a nadměrný odpočet

Vlastní daň = DPH na výstupu minus nárok na odpočet. Kladná hodnota je daň k úhradě finančnímu úřadu, záporná je nadměrný odpočet. Atribut `trans` ve `VetaD` se nastaví na `A` (vznikla povinnost) nebo `N` podle znaménka.

### 41.13.8 Krácený odpočet § 76 (koeficient)

Přijaté faktury s volbou **Krácený (§76)** u pole Nárok na odpočet DPH (viz [Přijaté faktury](23_Prijate_faktury.md#23117-danova-uznatelnost-a-narok-na-odpocet)) jsou doklady se společnými vstupy. Používají se zároveň pro plnění s nárokem na odpočet i pro plnění osvobozená bez nároku podle § 51 (typicky nájem, energie, účetní služby u firem, které mají vedle zdanitelných příjmů i osvobozené, např. pronájem, finanční nebo zdravotní služby). Na rozdíl od poměrného odpočtu § 75 se procento nezadává na dokladu, krátí se jedním koeficientem za celou firmu a rok.

Jak se to projeví v přiznání:

- Doklad se zařadí do sloupce **Krácený odpočet** na řádcích 40/41/42 (místo sloupce "V plné výši"). Daň na dokladu je plná, na dokladu se nic nekrátí.
- **Řádek 46** (odpočet daně celkem) se rozpadá na dvě čísla: "V plné výši" (řádky 40-45 mimo krácený sloupec) a "Krácený odpočet" (součet kráceného sloupce řádků 40-42).
- **Řádek 52** je krácený odpočet ř. 46 vynásobený zálohovým koeficientem platným pro daný rok. Vykazuje se v každém zdaňovacím období roku (měsíc i kvartál).
- **Řádek 53** je jen v posledním zdaňovacím období roku (prosinec u měsíčních plátců, Q4 u kvartálních). Systém dopočítá vypořádací koeficient ze skutečných dat celého roku a doplní rozdíl mezi ročním nárokem (roční krácený odpočet × vypořádací koeficient) a součtem, který už byl v jednotlivých obdobích uplatněn na ř. 52. Rozdíl může vyjít kladně i záporně.
- **Řádek 63** (odpočet daně celkem) = ř. 46 "V plné výši" + ř. 52 + ř. 53.

Zálohový a vypořádací koeficient: zálohový (§ 76 odst. 6) se používá během roku. Na začátku roku ho buď zadáte ručně (kvalifikovaný odhad), nebo se automaticky převezme z vypořádacího koeficientu předchozího, už vypořádaného roku. Vypořádací koeficient (§ 76 odst. 7) se počítá až ze skutečných dat celého roku, takže je zpravidla přesnější než odhad použitý během roku. Proto poslední období roku obsahuje dorovnání na ř. 53. Oba koeficienty se zaokrouhlují nahoru na celé procento (§ 76 odst. 5). Vyjde-li hodnota 95 % a víc, zaokrouhlí se rovnou na 100 % (plný nárok).

Bez nastaveného zálohového koeficientu pro daný rok nejde doklad s kráceným nárokem § 76 ani zaúčtovat, ani zahrnout do přiznání. Aplikace vrátí srozumitelnou chybu s výzvou koeficient nejdřív nastavit. Nastavení koeficientu a roční vypořádání jsou na stránce `Nástroje → Koeficient krácení (§76)` ([§ 41.8](#418-krok-za-krokem-koeficient-kraceni-odpoctu-76)). Stejné akce jsou dostupné i přes administrátorské API:

<!-- cols: 46 18 36 -->
| Endpoint | Kdo smí | Co dělá |
|---|---|---|
| `GET /api/reports/vat-coefficient?year=2026` | administrátor, účetní, jen čtení | Vrátí nastavený zálohový koeficient pro rok (případně automaticky převzatý z vypořádání předchozího roku) a vypořádací koeficient, pokud je rok vypořádaný. |
| `PUT /api/reports/vat-coefficient` | administrátor, účetní | Nastaví nebo změní zálohový koeficient (celé %, 0-100) pro rok. |
| `POST /api/reports/vat-coefficient/settle` | jen administrátor | Spočítá a uloží vypořádací koeficient za celý (uzavřený) rok ze skutečných ročních dat. Náhled ani stažení přiznání koeficient nikdy automaticky neuloží, vypořádání je vždy samostatný vědomý krok. |

**Plnění vyloučená z koeficientu.** Pro transakce podle § 76 odst. 4 zvolte v klasifikaci DPH odpovídající kód: `1m` / `2m` pro zdaněný prodej dlouhodobého majetku, nebo `3m` pro příležitostné osvobozené finanční či nemovitostní plnění. Doklad zůstane na běžném řádku 1/2 nebo 50 a současně se vykáže na řádku 51 ve správném sloupci. Vypořádací koeficient ho odečte z čitatele nebo jmenovatele.

**Omezení:**

- Zaúčtování ročního vypořádání (na účty 548/343, u firem s analytikami DPH proti 343.100) systém automaticky nedělá. Jen spočte a zobrazí částku na ř. 53 v přiznání. Zapište ji ručním zápisem do [Účetního deníku](52_Ucetni_denik.md). Nezaměňujte to s [měsíčním zúčtováním DPH](66_Ucetni_osnova.md#6686-mesicni-zuctovani-dph), které automatické je. To jen převádí obrat období na 343.900, roční vypořádání koeficientu neřeší.
- Kombinace reverse charge (samovyměření) a **Krácený (§76)** na jednom dokladu není podporovaná (ř. 43, kam se zrcadlí odpočet u samovyměření, nemá krácený protějšek). Takový doklad systém odmítne zaúčtovat i zahrnout do přiznání srozumitelnou chybou. Zaúčtujte ho a vykažte ručně.

### 41.13.9 Klasifikační kódy DPH

Každá faktura (nebo její řádek) má klasifikaci DPH, například `1`, `40`, `5`, `20`. Kód určuje, na který řádek přiznání položka patří.

**Vystavené doklady:**

<!-- cols: 12 50 18 20 -->
| Kód | Význam | Řádek DPHDP3 | KH / SH |
|---|---|---|---|
| **1** / **2** | Tuzemské plnění 21 % / 12 % | 1 / 2 | KH A.4 nebo A.5 |
| **1m** / **2m** | Prodej dlouhodobého majetku 21 % / 12 %, vyloučeno z koeficientu § 76 | 1 / 2 | KH A.4 / A.5 |
| **1c** / **2c** | Cestovní služba § 89, přirážka, 21 % / 12 % | 1 / 2 | KH A.4 s `kod_rezim_pl=1` |
| **26c** | Cestovní služba § 89, cena snížená o přirážku včetně daně | 26 | - |
| **1p** / **2p** | Použité zboží § 90, přirážka, 21 % / 12 % | 1 / 2 | KH A.4 s `kod_rezim_pl=2` |
| **3** | Osvobozené plnění bez nároku na odpočet (§ 51) | 50 | - |
| **3m** | Příležitostné osvobozené plnění vyloučené z koeficientu § 76 odst. 4 | 50 | - |
| **20** | Dodání zboží do jiného členského státu | 20 | SH kód plnění 0 |
| **22** | Poskytnutí služby do JČS (§ 9 odst. 1) | 21 | SH kód plnění 3 |
| **31** | Dodání zboží prostřední osobou při třístranném obchodu (§ 17) | 31 | SH kód plnění 2 |
| **23n** | Dodání nového dopravního prostředku neregistrované osobě (§ 19) | 23 | - |
| **24z** | Vybraná plnění (§ 110b odst. 2): služby nepovinným osobám a prodej zboží na dálku do JČS. OSS řádky se sem propisují samy, kód slouží pro ruční zařazení mimo OSS. | 24 | - |
| **25s** | Tuzemský přenos, stavební a montážní práce § 92e (dodavatel) | 25 | KH A.1, `kod_pred_pl=4` |
| **25s5** | Tuzemský přenos, odpad a šrot § 92c (dodavatel) | 25 | KH A.1, `kod_pred_pl=5` |
| **25s3** | Tuzemský přenos, dodání nemovité věci § 92d (dodavatel) | 25 | KH A.1, `kod_pred_pl=3` |
| **26** | Vývoz zboží do 3. země (§ 66) | 22 | - |
| **26s** | Služba s místem plnění mimo tuzemsko, 3. země (§ 9 odst. 1) | 26 | - |

**Přijaté doklady:**

<!-- cols: 12 50 18 20 -->
| Kód | Význam | Řádek DPHDP3 | KH |
|---|---|---|---|
| **40** / **41** | Tuzemské plnění 21 % / 12 % s nárokem na odpočet | 40 / 41 | KH B.2 nebo B.3 |
| **42** | Tuzemské plnění bez nároku na odpočet | mimo přiznání | - |
| **5** | Tuzemský přenos, stavební a montážní práce § 92e (příjemce) | 10 + odpočet 43 | KH B.1, `kod_pred_pl=4` |
| **5c** | Tuzemský přenos, odpad a šrot § 92c (příjemce) | 10 + 43 | KH B.1, `kod_pred_pl=5` |
| **5d** | Tuzemský přenos, dodání nemovité věci § 92d (příjemce) | 10 + 43 | KH B.1, `kod_pred_pl=3` |
| **23** | Pořízení zboží z JČS (§ 25) | 3 + 43 | KH A.2 |
| **24e** | Přijetí služby z JČS (§ 9 odst. 1) | 5 + 43 | KH A.2 |
| **24** | Přijetí služby ze 3. země nebo od osoby neusazené v tuzemsku | 12 + 43 | KH A.2 |
| **25** | Dovoz zboží ze 3. země | 7 | - |
| **30** | Pořízení zboží prostřední osobou při třístranném obchodu (§ 17) | 30 | - |

Kódy s příponou (`1m`, `25s5`, `26s` a další) se od základní varianty liší jediným atributem: vyloučením z koeficientu, kódem předmětu plnění, zvláštním režimem, nebo řádkem přiznání. Číselník je editovatelný. V `Systém → Sazby a číselníky`, na záložce **Klasifikace DPH**, si můžete přidat vlastní kód, včetně kódu předmětu plnění s písmenným sufixem (`1a`, `3a`) z číselníku MFČR.

**Cestovní služba podle § 89** se na dokladu rozepisuje na dva řádky. Přirážka snížená o daň má kód `1c` nebo `2c` a jde na ř. 1 nebo 2 a do kontrolního hlášení s režimem 1. Zbytek ceny, tedy cena snížená o přirážku včetně daně, má kód `26c` a sazbu 0 % a jde na ř. 26 mimo kontrolní hlášení (metodika GFŘ č. j. 67743/21/7100-20116-050701). Příklad: cena 140 Kč, náklady 120 Kč, přirážka 20 Kč dává řádek `1c` se základem 16,53 Kč a DPH 3,47 Kč a řádek `26c` se 120 Kč. Do A.4 nebo A.5 se doklad zařadí podle celé částky dokladu včetně daně, ne podle přirážky.

MyÚčto přirážku nepočítá, její výši určíte vy. U zálohy přebírá daňový doklad k přijaté platbě kódy z proformy a platbu dělí v poměru jejích řádků. Pokud se přirážka ze zálohy podle § 89 odst. 4 až 6 liší, opravte částky v konceptu daňového dokladu před vystavením. Vyúčtovací faktura a odpočty záloh kódy přebírají také. PDF vydané faktury vypisuje základ a DPH, ale § 89 odst. 14 uvedení daně z přirážky na dokladu pro zákazníka zakazuje, takže takové PDF zákazníkovi neposílejte.

### 41.13.10 Automatické přiřazení klasifikace

Pokud na fakturu ani řádek kód nevyberete, doplní ho systém sám. Rozhoduje:

- sazba na řádku,
- země protistrany (tuzemsko, EU, 3. země),
- reverse charge na dokladu,
- u přijatých dokladů navíc plátcovství vaší firmy k datu dokladu a povaha plnění (poplatek orgánu veřejné moci, viz níže),
- u nulové sazby do zahraničí měrná jednotka položky: časová (h, den, měsíc) znamená službu, fyzikální míra nebo balení (kg, l, m², paleta) zboží. Jednotka `ks` je neutrální a rozhodne statistický výchozí stav (služba).

U přijatých dokladů přiřazuje kód jediné místo, a to ukládání řádků. Hlavička dokladu ho jen přebírá z dominantního řádku, sama nikdy nerozhoduje. Ručně zvolený kód na hlavičce zůstává. Výkazy čtou kód z řádku a teprve pak z hlavičky. Výjimkou je kód bez nároku na odpočet v hlavičce (`42`, `mimo` a vlastní kódy bez řádku přiznání a oddílu KH): řádek bez vlastního kódu ho při uložení převezme, místo aby dostal `40` nebo `41` a uplatnil odpočet, který hlavička odepírá. Kód s nárokem se z hlavičky nepřebírá, protože by 12% řádek pod hlavičkou `40` skončil na ř. 40.

Má-li přijatý doklad **rozpis DPH** (rozdělení odpočtu a zaúčtování, viz [Přijaté faktury](23_Prijate_faktury.md#ucetni-alokace-casti-dokladu)), bere přiznání i kontrolní hlášení nárok na odpočet a kód z částí rozpisu, hlavička ani položky o nich nerozhodují. Detail dokladu proto ukazuje nárok podle rozpisu a upozorní, když se hlavička od rozpisu liší.

Hodnoty se berou z číselníku klasifikací platného k DUZP dokladu, takže po změně sazby dostanou starší doklady starou klasifikaci a nové novou.

> [!WARNING]
> Automatika je pomůcka, ne rozhodnutí. Daňové zařazení dokladu je odpovědnost uživatele nebo jeho účetní. Návrh je vždy přepsatelný a u nejednoznačných případů systém raději nenavrhne nic, než aby potichu vyrobil daňovou povinnost nebo odpočet.

#### Kdy se kód záměrně nepřiřadí

Prázdná klasifikace je někdy správný výsledek, ne opomenutí:

- **Nulová sazba v tuzemsku.** Nula sama nerozlišuje osvobození bez nároku (§ 51), plnění mimo předmět daně, přeúčtování nákladů, náhradu škody ani smluvní pokutu. Přiznání na neklasifikovaný nulový řádek upozorní a zahrne ho, až mu vyberete kód.
- **Poplatek orgánu veřejné moci:** soudní a správní poplatky, kolky, evropský platební rozkaz, `Gerichtskosten`, `court fee`. Orgán při výkonu veřejné správy není osobou povinnou k dani (§ 5 odst. 4 ZDPH), takže plnění není předmětem daně ani u zahraničního soudu či úřadu: nesamovyměřuje se podle § 9 odst. 1 a doklad nepatří do přiznání ani do KH. Aplikace ho pozná z popisu položky, nechá bez klasifikace a řekne to upozorněním v detailu dokladu. Účetně jde o běžný náklad (typicky 538 Ostatní daně a poplatky).
- **Sazba, kterou český číselník nezná** (např. německých 19 %). Cizí daň nelze uplatnit jako odpočet, takže se nepřiřadí ani `40`, ani `41`. Doklad dostane upozornění, že bez zásahu nevstoupí do přiznání ani do KH. Zkontrolujte sazby a rozhodněte, zda jde o náklad včetně cizí daně, nebo o špatně vytěžený doklad.

#### Co automatika u přijatých dokladů udělá

<!-- cols: 24 16 14 46 -->
| Dodavatel | Sazba | Reverse charge | Výsledek |
|---|---|---|---|
| tuzemský | 21 % / 12 % | ne | `40` / `41` |
| tuzemský | 0 % | ne | bez kódu |
| tuzemský, firma je plátce | libovolná | ano | `5` (§ 92a, ř. 10 + KH B.1) |
| tuzemský, firma je neplátce nebo identifikovaná osoba | libovolná | ano | tuzemský přenos se nepřiřadí, § 92a funguje jen mezi plátci |
| z EU | 0 % | jedno | `24e` (přijetí služby, ř. 5) |
| z EU | 21 % | ano | `23` (pořízení zboží z JČS, ř. 3) |
| ze 3. země | 0 %, nebo 21 % + RC | jedno | `24` (služba od neusazené osoby, ř. 12) |
| jakýkoli | 0 % | poplatek úřadu | bez kódu (mimo předmět daně) |

Zahraniční samovyměření podle § 108 se týká i identifikované osoby, kvůli němu ten režim existuje. Nárok na odpočet ale nemá, takže se jí zrcadlový odpočet na ř. 43 nepřizná.

#### Řádek přiznání se řídí sazbou, ne jen kódem

Když zvolíte kód pro základní sazbu, ale řádek nese sníženou (nebo obráceně), použije se dvojče kódu odpovídající skutečné sazbě (1 a 2, 40 a 41, u samovyměření 3 a 4, 5 a 6, 7 a 8, 10 a 11, 12 a 13). Bez toho by přiznání vykázalo základ v 21% sloupci, zatímco kontrolní hlášení rozděluje podle skutečné sazby do 12% sloupce, a oba výkazy by se rozešly. Vlastní přemapování kódu v číselníku (per firma) tím dotčené není, přepíná se jen při skutečném rozporu sazeb.

### 41.13.11 Ruční volba klasifikace

V editoru faktury (vystavené i přijaté) je sekce **Klasifikace** s výběrem kódu. Můžete:

- nechat pole prázdné, použije se automatika,
- vybrat konkrétní kód jako ruční volbu (např. specifický kód pro export).

### 41.13.12 Reverse charge v cizí měně

U plnění s reverse charge v cizí měně (typicky kódy 5, 23, 24):

1. Kurz faktury se aplikuje na základ DPH (základ bez DPH × kurz).
2. Samovyměřená daň se dopočte ze sazby (základ v CZK × sazba / 100), protože dodavatel vystavil bez DPH.
3. Odpočet se uvede na ř. 43 jako zrcadlo primárního řádku (3, 10 nebo 12).

Příklad: faktura z Německa, 1 000 EUR při kurzu 25, klasifikace `23` dává ř. 3 (`p_zb23=25000`, `dan_pzb23=5250`), ř. 43 (`nar_zdp23=25000`, `od_zdp23=5250`) a KH sekci A.2.

> [!WARNING]
> Dvojice `odp_rezim` a `odp_rez_nar` patří na ř. 45 (korekce odpočtu podle § 75, § 77 a § 79: registrace, vyrovnání), ne na ř. 43. Zrcadlový odpočet ze samovyměření nese `nar_zdp23` a `od_zdp23`. Při ruční editaci XML byste jinak vykázali korekci odpočtu místo odpočtu ze samovyměření.

### 41.13.13 Pořízení dlouhodobého majetku

Zaškrtávací pole **Pořízení dlouhodobého majetku** v editoru přijaté faktury označí doklad za majetek vymezený v § 4 odst. 4 písm. c) (vozidlo, stroj). U smíšených dokladů lze příznak nastavit i po řádcích.

Hodnota se v DPHDP3 uvede na:

- **ř. 40** (nebo 41/42/43 podle klasifikace) jako běžný odpočet,
- **ř. 47** (atribut `nar_maj`) jako doplňující údaj o hodnotě majetku.

Daň se v součtech ř. 46 neduplikuje, ř. 47 je informativní. V [Knize DPH](42_Kniha_DPH.md) je samostatná sekce **47.047** se sumací.

### 41.13.14 Kontrolní hlášení: sekce a pravidla zařazení

Právnická osoba podává KH měsíčně, fyzická osoba podle svého zdaňovacího období měsíčně nebo čtvrtletně. Identifikovaná osoba KH nepodává. KH obsahuje sekce:

- **A.1** je plnění v režimu přenesené daňové povinnosti (dodavatel). Doklad, který nese víc režimů § 92 najednou (např. stavební práce a odpad na jedné faktuře), dá větu za každý kód předmětu plnění, jak to vyžaduje XSD i párování s protistranou. Kód předmětu plnění nese klasifikace řádku (`25s` = 4 stavební práce, `25s5` = 5 odpad a šrot, `25s3` = 3 nemovitá věc).
- **A.2** je pořízení zboží z jiného členského státu a přijaté služby od osoby neusazené v tuzemsku podle § 24, včetně třetích zemí. Typicky kód `23`, `24` nebo jeho zahraniční varianta.
- **A.4** jsou tuzemská plnění s DPH nad 10 000 Kč (individuálně).
- **A.5** jsou tuzemská plnění s DPH do 10 000 Kč (sumace).
- **B.1** je přenesená daňová povinnost (odběratel). Rozpad na věty podle kódu předmětu plnění platí stejně jako u A.1. Kódy nesou klasifikace `5`, `5c`, `5d`.
- **B.2** jsou přijatá tuzemská plnění nad 10 000 Kč.
- **B.3** jsou přijatá tuzemská plnění do 10 000 Kč (sumace).

Pravidla zařazení dokladů do sekcí odpovídají metodice GFŘ:

<!-- cols: 24 76 -->
| Pravidlo | Detail |
|---|---|
| **Období** | DUZP, jinak datum vystavení, v daném měsíci. Doklad bez DUZP se zařadí podle data vystavení a nevypadne. |
| **Stav** | Bez konceptů a stornovaných dokladů. Storno je součást auditní stopy, do KH nepatří. |
| **Práh 10 000 Kč** | Porovnává se absolutní hodnota celkové částky včetně DPH. Záporný dobropis nad limit (např. -25 000 Kč) jde tedy správně do A.4 / B.2 jednotlivě, ne do sumace. |
| **DIČ protistrany** | Do A.4 / B.2 patří jen plnění nad limit a s DIČ plátce. Plnění bez DIČ (B2C, doklad od neplátce) jde do sumace A.5 / B.3 bez ohledu na částku. |
| **Jen zdanitelná plnění** | Do A.4, A.5, B.2 a B.3 patří jen plnění se zdanitelným základem 21 / 12 %. Osvobozená plnění, EU dodání, vývoz a reverse charge (kde je uložená sazba 0) se sem nezařazují, netvoří nulové řádky. |

Kam který doklad patří:

- **A.1** (vystavené RC): faktury v režimu přenesené daňové povinnosti (dodavatel). Pozná se podle klasifikačního kódu s příznakem reverse charge, nebo podle příznaku na faktuře. Vyžaduje DIČ odběratele.
- **A.2** (zahraniční samovyměření): přijaté faktury s klasifikací patřící do sekce A.2 (typicky pořízení zboží kód 23, služby z EU a služby od osoby neusazené ve třetí zemi). Daň je samovyměřená (základ × sazba). Doklad se nezařadí zároveň do B.2 ani do B.1.
- **A.4 / A.5** (vystavená tuzemská): viz pravidla v tabulce.
- **B.1** (přijaté RC): tuzemský reverse charge (kód 5). Pořízení z JČS (A.2) sem nepatří, i když je také samovyměřené.
- **B.2 / B.3** (přijatá tuzemská): analogicky k A.4 / A.5. Vylučují se doklady, které patří do A.2, B.1 nebo reverse charge, aby se neduplikovaly.

**Rekapitulace (VetaC)** sčítá obraty napříč sekcemi. `pln_rez_pren` odpovídá A.1, `rez_pren23` a `rez_pren5` odpovídají B.1 v základní a snížené sazbě.

**Atributy A.2** (zahraniční samovyměření): `k_stat` (země dodavatele), `vatid_dod` (DIČ bez prefixu země), `c_evid_dd` (číslo dokladu dodavatele), `dppd` (datum povinnosti přiznat daň), `zakl_dane1` a `dan1` (21 %), `zakl_dane2` a `dan2` (12 %). Daň se dopočítá ze základu × sazba / 100, protože dodavatel fakturuje bez DPH. Kniha DPH ji u řádků RC počítá stejně. Oddíl A.2 zahrnuje také přijaté služby od osoby neusazené v tuzemsku ze třetí země (kód `24`, ř. 12/13 přiznání). U takového dodavatele může zůstat VAT ID i kód členského státu prázdný.

### 41.13.15 Kontrolní hlášení: typ podání

Analogicky k přiznání nabízí stránka Kontrolní hlášení pole **Typ podání**:

<!-- cols: 38 62 -->
| Typ | Kdy použít |
|---|---|
| **Řádné** (výchozí) | Standardní měsíční (u fyzické osoby též kvartální) podání |
| **Řádné/opravné (§ 101f/1 - před lhůtou)** | Nahrazuje už podané řádné hlášení, dokud za období neuplynula lhůta |
| **Následné (§ 101f/2 - po lhůtě)** | Podání po lhůtě, oprava už podaného hlášení |
| **Následné/opravné** | Oprava už podaného následného hlášení |
| **Odpověď na výzvu - nemám povinnost podat KH** | Rychlá odpověď na výzvu správce daně, podává se bez oddílů A, B a C, vyžaduje č.j. výzvy |
| **Odpověď na výzvu - potvrzuji správnost posledního KH** | Totéž, potvrzení správnosti posledního hlášení |

Po výběru **Následné** nebo **Následné/opravné** se zobrazí pole **Datum zjištění** (kdy jste zjistili, že je potřeba podat opravu) a **Č.j. výzvy** (číslo jednací výzvy finančního úřadu, pokud hlášení reaguje na doručenou výzvu). Na doručenou výzvu má účetní jen 5 pracovních dnů, proto pole vyplňte, pokud podání na výzvu navazuje.

> [!WARNING]
> Na rozdíl od dodatečného přiznání k DPH se následné kontrolní hlášení vždy počítá jako úplné. Obsahuje všechny údaje za dané období znovu (sekce A.1-A.5, B.1-B.3), ne jen rozdíl oproti dřívějšímu podání. To vyžaduje přímo zákon, rozdílový způsob se u kontrolního hlášení nepoužívá.

### 41.13.16 Zvláštní režimy a opravy nedobytných pohledávek v KH

V `Systém → Sazby a číselníky`, na záložce **Klasifikace DPH**, lze u vlastního kódu nastavit:

- režim KH `0` (běžný), `1` (cestovní služba § 89) nebo `2` (použité zboží § 90),
- příznak `P` pro opravu nedobytné pohledávky podle § 46 / § 74b.

Hodnoty se přenesou do `VetaA4.kod_rezim_pl` a `VetaA4/VetaB2.zdph_44`. U vystaveného dobropisu, který snižuje daň, přiznání zároveň připomene ověření data doručení opravného daňového dokladu podle § 42 ZDPH.

Příznak `zdph_44` na klasifikačním kódu označuje zvláštní režim v KH. Samotnou korekci odpočtu dlužníka podle § 74b připravuje a eviduje samostatná stránka, viz [§ 41.9](#419-krok-za-krokem-oprava-odpoctu-u-neuhrazenych-zavazku-74b) a [§ 41.13.18](#411318-oprava-odpoctu-74b-jak-se-pocita).

### 41.13.17 OSS a přiznání k DPH

Do OSS přiznání vstupují jednotlivé OSS řádky vydaných faktur, jejichž datum zdanitelného plnění patří do vybraného kvartálu. Aplikace je seskupí podle státu spotřeby, typu plnění, typu sazby a sazby DPH a oddělí běžná plnění od oprav vztahujících se k dřívějším obdobím. Výpočet vychází z řádkových základů a daně v daňové evidenci, ne jen z celkové částky hlavičky faktury. Zařazení do OSS se odvozuje automaticky ve všech vstupních kanálech, ruční označování řádků není potřeba.

Daň z OSS řádků do českého přiznání k DPH nevstupuje a OSS řádky nejsou v kontrolním ani souhrnném hlášení. V přiznání k DPH se ale jejich základ bez daně uvádí na **ř. 24** "Vybraná plnění (§ 110b odst. 2)", stejně jako v Knize DPH (kód 24z).

Celý režim OSS popisuje samostatná kapitola [Režim OSS (One Stop Shop)](45_OSS.md): nastavení a registrace, odvození řádku, plnění k ručnímu posouzení, hromadná úprava, doložka na dokladu, účtování na 345.100, sledování prahu 10 000 EUR, přepočet kurzem ECB, opravy minulých období, XML `OSSEI1`, archiv podání, rekonciliace a evidence § 110f.

#### Co se z OSS promítne do přiznání k DPH

Ř. 24 přiznání obsahuje hodnotu plnění, na která je použit režim OSS: služby osobám nepovinným k dani s místem plnění v jiném členském státě i prodej zboží na dálku. Uvádí se základ bez zahraniční daně, přepočtený na Kč kurzem dokladu, v přiznání za období, do kterého patří datum uskutečnění plnění (ne za kvartál OSS podání). Dobropis k OSS faktuře ř. 24 snižuje. Řádek se nesčítá do daně na výstupu (ř. 62), ale vstupuje do výpočtu koeficientu podle § 76 stejně jako ostatní řádky 20 až 26. Přiznání identifikované osoby ř. 24 neobsahuje.

Přiznání k DPH hlásí varování se seznamem dokladů u řádků, které zůstaly mimo OSS s příznakem "k ručnímu posouzení". Vstupují na ř. 1 a 2, aniž to kdo potvrdil. Zakládají je kanály běžící bez lidského zásahu (pravidelná fakturace, synchronizace z iDokladu a Fakturoidu, čtení PDF, vlastní integrace přes API). Projděte je dřív, než přiznání podáte. Najdete je filtrem **Místo plnění (OSS)** v seznamu faktur, volbou **Nejisté - v tuzemsku** ([§ 14.1.1](14_Faktury.md#nejiste-misto-plneni-oss)). Druhou skupinu, tedy řádky zařazené do OSS s týmž otazníkem, hlásí náhled OSS podání. Rozdíl mezi nimi vysvětluje oddíl [Plnění k ručnímu posouzení](45_OSS.md#45109-plneni-k-rucnimu-posouzeni) v kapitole OSS.

Účtování OSS daně na vlastní účet 345.100 je důvod, proč zůstatek 343 jde s přiznáním k DPH srovnat. Podrobně oddíl [Účtování OSS daně](45_OSS.md#451012-uctovani-oss-dane) v kapitole OSS.

### 41.13.18 Oprava odpočtu § 74b: jak se počítá

Stránka pro zvolený měsíc nejprve vytvoří náhled nanečisto. Vybírá tuzemské přijaté zdanitelné doklady s uplatněným odpočtem, vylučuje reverse charge a stornované doklady a porovnává je s evidovanými úhradami. Do úhrady vstupuje záloha, bankovní párování i pokladna. Stav **Zaplaceno** je autoritativní signál plné úhrady i u starších dokladů bez detailní historie plateb.

Korekce vzniká po uplynutí šesti kalendářních měsíců následujících po měsíci splatnosti. Aplikace počítá:

`cílová korekce = původně uplatněný odpočet × neuhrazená část / částka s DPH`

Původně uplatněný odpočet respektuje plný, poměrný, krácený i nulový nárok. Od cíle se odečte čistá korekce zaevidovaná v dřívějších obdobích. Výsledná delta je buď nové **snížení odpočtu**, nebo **obnovení odpočtu** po další úhradě. Nulový rozdíl se znovu nezapisuje.

Příklad: z odpočtu 2 100 Kč zůstává 40 % závazku neuhrazeno, cílová korekce je 840 Kč. Po úhradě na 10 % neuhrazeného zbytku klesne cíl na 210 Kč a rozdíl 630 Kč se zobrazí jako obnovení odpočtu.

Teprve zaevidované nenulové pohyby se promítnou do:

- DPHDP3 na řádky 40/41 a do související hodnoty řádku 34,
- kontrolního hlášení B.2 s příznakem `zdph_44 = P`,
- Knihy DPH.

Náhled nic nezapisuje ani neúčtuje do deníku.

### 41.13.19 Opravy DPH § 43, § 79 a § 79a: pravidla

Stránka `Daně → Opravy DPH (§43, §79)` vede dvě samostatné evidence. Zápis vyžaduje oprávnění finalizovat výkazy, čtení běžné oprávnění k reportům.

**§ 43 - oprava výše daně.** Používá se při chybně určené výši daně, například při nesprávné sazbě nebo výpočtu. Není to dobropis podle § 42: § 42 opravuje základ daně a patří do období, kdy byl opravný doklad doručen (tedy dopředu), kdežto § 43 patří zpětně do období původního plnění a vstupuje do dodatečného přiznání. Použije se sazba platná u původního plnění, ne dnešní.

U záznamu vyberete vydanou nebo přijatou fakturu, období původního plnění, sazbovou skupinu, změnu základu a daně, datum doručení opravného dokladu, jeho číslo a povinný důvod. Změna DPH nesmí být nulová. Datum doručení jen určuje, kdy nejdřív šlo opravu provést. Aplikace hlídá také lhůtu pro stanovení daně: standardně tři roky od 25. dne po konci původního zdaňovacího období. U čtvrtletního plátce se konec posuzuje za celé čtvrtletí. Po uplynutí lhůty opravit nelze.

Evidované částky se podle sazby přičtou k řádkům 1 nebo 2 DPHDP3 za období původního plnění. Evidence sama nevytváří účetní zápis a nepřepočítává zdrojovou fakturu.

**§ 79 a § 79a - registrace a zrušení registrace.** Záložka eviduje odpočet při registraci a jeho snížení při zrušení registrace. Položky zadává účetní ručně, protože systém z dokladu nepozná, zda zásoba nebo majetek k rozhodnému dni stále tvoří obchodní majetek. Zadává se druh operace, popis, datum pořízení, rozhodný den, druh majetku (zásoba nebo dlouhodobý majetek), DPH na vstupu a u dlouhodobého majetku pětiletá nebo desetiletá lhůta. Rozhodný den (den vzniku plátcovství, nebo den zrušení registrace) určuje období vykázání.

- při registraci vstupuje nárok kladně, pokud bylo plnění pořízeno nejvýše 12 měsíců před vznikem plátcovství, a uvádí se v přiznání za období, do něhož spadá den vzniku plátcovství,
- při zrušení registrace se u zásob vrací celý odpočet záporně v posledním období registrace,
- u dlouhodobého majetku se vrací jen podíl za roky zbývající z pěti- nebo desetileté lhůty. Po jejím uplynutí je částka nulová.

Součet platných položek se promítá na řádek 45 DPHDP3, zaokrouhlený na celé Kč. Ani tato evidence sama neúčtuje do účetního deníku.

### 41.13.20 Nedobytné pohledávky § 46

Stránka `Nástroje → Nedobytné pohledávky (§46)` (§ 46 až § 46g ZDPH) pracuje s věřitelskou opravou základu daně u nedobytné pohledávky a s obnovou po úhradě.

Seznam kandidátů ukazuje neuhrazené tuzemské vydané faktury s daní na výstupu po splatnosti (bez reverse charge). Nárok na opravu z něj neplyne. Právní důvod (insolvence, exekuce, smrt dlužníka, likvidace, malá pohledávka do 10 000 Kč po 6 měsících) a doručení opravného daňového dokladu dlužníkovi dokládá účetní při evidenci opravy. Datum doručení určuje zdaňovací období, ve kterém se oprava vykáže (§ 46f). Oprava se promítne do přiznání (ř. 1/2 záporně, ř. 33) a KH (A.4) za období doručení. Obnova po úhradě (§ 46e) se počítá automaticky z evidovaných úhrad: uhrazené, i částečně uhrazené dříve opravené pohledávky zvýší daň zpět ve stejném poměru. Obnovy se evidují za zvolený měsíc tlačítkem **Zaevidovat obnovy** a jejich součet ukazuje řádek **Obnovy celkem (zvýšení daně)**.

Citlivý daňový výstup. Před podáním ověřte s daňovým poradcem.

### 41.13.21 Co kontrola podání neumí

Křížové kontroly porovnávají sestavy vypočtené z aktuálních dat aplikace. Neumějí načíst skutečně odeslané DPHDP3 nebo DPHKH1 z portálu a porovnat je řádek po řádku. Pokud účetní XML na portálu ručně upraví, uložte jeho finální kopii a potvrzení mimo aplikaci a při další opravě ji porovnejte ručně. Archivní snapshot je věrným obrazem souboru vytvořeného aplikací, ne automatickým potvrzením, že právě tento soubor byl přijat finanční správou.

### 41.13.22 Změna sazby DPH s budoucí platností

Pokud se sazba změní, postupujte takto:

1. V `Systém → Sazby a číselníky`, na záložce **Sazby DPH**, nastavte u dosavadní sazby **Platí do** na den před účinností změny. Založte novou sazbu s novým procentem a datem **Platí od**.
2. Na záložce **Klasifikace DPH** upravte u odpovídající klasifikace sazbu, nebo ponechte samostatné klasifikace pro dosavadní a novou sazbu.
3. Vystavené doklady si ponechají sazbu uloženou na svých řádcích.
4. Doklady s DUZP v nové účinnosti použijí platnou sazbu a odpovídající výchozí klasifikaci.

### 41.13.23 Podpora pro daňového poradce

Pokud XML zpracovává externí účetní:

1. Vyplňte v nastavení blok **Sestavitel přiznání (účetní)** (jméno, funkce, telefon, e-mail).
2. Doporučujeme u poradce ověřit XML před prvním podáním.
3. Před odesláním použijte kontroly v otevřeném formuláři EPO. Serverový parametr `test=1` se týká podepsaného ZAREP podání a není součástí asistovaného předání.

## 41.14 Související kapitoly

- [Kniha DPH](42_Kniha_DPH.md)
- [Souhrnné hlášení](44_Souhrnne_hlaseni.md)
- [Režim OSS](45_OSS.md)
- [Hromadný export](48_Hromadny_export.md)
- [Archiv podání a daňová rekonciliace](49_Archiv_podani_a_rekonciliace.md)
- [Přijaté faktury](23_Prijate_faktury.md)
- [Identifikovaná osoba (Fakturujeme)](40_Fakturujeme.md#4094-identifikovana-osoba-6g-6l-zdph)
