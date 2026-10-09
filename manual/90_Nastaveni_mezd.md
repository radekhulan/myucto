# 90. Nastavení mezd

> Návod, jak jednou za firmu nastavit zaměstnavatele, mzdové účtárny,
> variabilní symbol ČSSZ, účty institucí, předkontace a politiky a jak
> převzít zaměstnance, docházku a identifikátory ČSSZ přes stránku Importy.
> Pro mzdové účetní a správce mezd.

## 90.1 Kdy to potřebujete

- Zavádíte mzdy ve firmě a potřebujete vyplnit zaměstnavatele a účtárny.
- ČSSZ vám přidělila (nebo změnila) variabilní symbol zaměstnavatele.
- Zdravotní pojišťovna, finanční úřad nebo pojistitel odpovědnosti má nový
  bankovní účet.
- Měníte výplatní den, výměru dovolené nebo předkontace mezd.
- Přecházíte z jiného mzdového programu a chcete převzít zaměstnance
  z registrací a měsíčních hlášení.
- Každý měsíc nahráváte podklady z docházkového systému.
- Potřebujete doplnit OIČ zaměstnancům z exportu POHODY.

<!-- cols: 26 44 30 -->
| Kdy | Co udělat | Kde v aplikaci |
|---|---|---|
| jednou při zavádění | Zaměstnavatel, účtárny, VS ČSSZ, účty institucí, předkontace | `Mzdy → Nastavení mezd`, [§ 90.3](#903-krok-za-krokem-zamestnavatel-a-mzdove-uctarny) až [§ 90.7](#907-krok-za-krokem-automaticke-uctovani) |
| jednou při zavádění | Potvrdit profil REGZEL | záložka **Podání**, [§ 90.9](#909-krok-za-krokem-profil-regzel-a-rocni-udaje-pro-jmhz) |
| jednou při přechodu | Převzít zaměstnance a historii z JMHZ a registrací | `Mzdy → Importy`, záložka **JMHZ**, [§ 90.10](#9010-krok-za-krokem-import-zamestnancu-z-jmhz-a-registraci) |
| každý měsíc | Nahrát docházku | záložka **Docházka**, [§ 90.11](#9011-krok-za-krokem-mesicni-import-dochazky) |
| před prosincovým JMHZ | Vyplnit roční údaje zaměstnavatele | záložka **Podání**, **Roční údaje zaměstnavatele pro JMHZ** |
| při změně | Nový účet instituce, nová sazba, nová politika | příslušná záložka, vždy jako nový záznam s účinností |

## 90.2 Než začnete

1. **Oprávnění.** Stránku upravujete s oprávněním **Nastavení mezd**
   (`payroll.settings`); bez něj ji uvidíte jen pro čtení. Záložku **Podání**
   upravuje oprávnění k mzdovým podáním, skrytá varování obnovuje jen ten, kdo
   smí schvalovat mzdové běhy. Importy mají vlastní oprávnění, viz jednotlivé
   postupy.
2. **Podklady.** Připravte ověřené údaje: oznámení ČSSZ o registraci
   zaměstnavatele (variabilní symbol, kód OSSZ), čísla plátce u zdravotních
   pojišťoven, bankovní účty institucí, kódy finanční správy a schválený účtový
   rozvrh.
3. **Datová schránka** se nastavuje samostatně (`Mzdy → Datová schránka`, viz
   [Datová schránka](97_Datova_schranka.md)). Její nastavení samo nic
   neodesílá ani nenačítá.
4. Přepínač **Vést mzdy** je jen v obecném Nastavení firmy, na této stránce se
   neduplikuje.

### 90.2.1 Jak je stránka uspořádaná

`Mzdy → Nastavení mezd` (nadpis stránky je **Nastavení zaměstnavatele**) má
sedm záložek:

<!-- cols: 30 70 -->
| Záložka | K čemu slouží |
|---|---|
| **Zaměstnavatel a účtárny** | Registrace zaměstnavatele, kód OSSZ, výchozí pojišťovna a účtárna, kontakt pro mzdy, mzdové účtárny a jejich variabilní symboly ČSSZ |
| **Účty institucí** | Platební účty ČSSZ, finančního úřadu, pojišťoven a dalších příjemců; sazba zákonného pojištění odpovědnosti |
| **Automatické účtování** | Předkontace mezd, pojistného, daně a srážek |
| **Politiky a připravenost** | Výplatní termín, výměra dovolené, příplatky, automatizace, doručení výplatnic; kontrola připravenosti |
| **Podání** | REGZEL profil zaměstnavatele a roční údaje zaměstnavatele pro JMHZ |
| **Dimenze** | Mzdová střediska, zakázky a činnosti |
| **Skrytá varování** | Varování mzdového běhu, která jste trvale skryli |

Záložky **Zaměstnavatel a účtárny** a **Automatické účtování** se ukládají
tlačítkem **Uložit** nahoře nebo dole (**Zahodit změny** vrátí uložený stav).
Ostatní záložky mají vlastní tlačítka u každého záznamu.

## 90.3 Krok za krokem: zaměstnavatel a mzdové účtárny

1. Otevřete `Mzdy → Nastavení mezd`, záložku **Zaměstnavatel a účtárny**.
2. V **Registrace zaměstnavatele** vyplňte **Registrační číslo
   zaměstnavatele**, **Kód správy sociálního zabezpečení** (trojmístný, např.
   110) a **Kód výchozí zdravotní pojišťovny**.
3. V **Mzdové účtárny** klikněte na **Přidat účtárnu** (u první **Založit
   první účtárnu**). Vyplňte **Název**, **Kód** se předvyplní sám.
4. Vyberte **Výchozí mzdová účtárna** (musí být aktivní).
5. Vyplňte **Kontakt pro mzdy** (jméno, e-mail, telefon).
6. Klikněte na **Uložit**.
7. Teprve u uložené účtárny zadejte variabilní symbol ČSSZ
   ([§ 90.4](#904-krok-za-krokem-variabilni-symbol-cssz-uctarny)).

**Jak poznáte, že je hotovo:** Aplikace hlásí „Nastavení zaměstnavatele bylo
uloženo." a účtárna je v seznamu jako **Aktivní**.

## 90.4 Krok za krokem: variabilní symbol ČSSZ účtárny

Variabilní symbol ČSSZ přiděluje okresní správa při registraci zaměstnavatele.
Jde na každý platební příkaz sociálního pojistného i do všech podání ČSSZ.

1. Na záložce **Zaměstnavatel a účtárny** klikněte u účtárny na **Spravovat
   účinné registrace**.
2. Do **Účinné od** zadejte den, od kterého symbol platí podle oznámení ČSSZ.
   Výchozí je začátek vedení mezd v MyÚčtu, ne dnešek.
3. Do **Variabilní symbol ČSSZ** opište desetimístný symbol přesně z oznámení
   ČSSZ.
4. Volitelně vyplňte **Reference zdroje** (odkaz na výměr nebo rozhodnutí).
5. Klikněte na **Uložit**.
6. Pro zkušební podání do testovacího prostředí ČSSZ vyplňte u účtárny
   **Testovací VS ČSSZ** a stránku uložte.

**Jak poznáte, že je hotovo:** V dialogu je v historii řádek „datum · symbol"
a aplikace hlásí „Nová účinná registrace ČSSZ byla uložena."

> [!WARNING]
> Desetimístný variabilní symbol ČSSZ musí projít kontrolní číslicí (Luhnův
> součet) a začínat kódem okresní správy sociálního zabezpečení. Aplikace to
> ověří už při uložení nového nebo změněného symbolu a chybu ukáže u pole.
> Dříve uložený neplatný symbol (například zástupný z převodu) uložení jiných
> údajů nezablokuje, ale zastaví přípravu přihlášky i měsíčního hlášení; ČSSZ by
> takové podání odmítla. Symbol opravte postupem v
> [§ 90.14.1](#90141-mzdove-uctarny-a-registrace-u-cssz).

Chybné datum účinnosti opravíte tlačítkem **Vzít zpět** u nejnovějšího záznamu
a novým zadáním. Starší verzi doplnit nejde.

## 90.5 Krok za krokem: účty institucí

1. Otevřete záložku **Účty institucí** a klikněte na **Přidat účet instituce**.
2. Vyberte **Typ instituce**. U zdravotní pojišťovny vyberte pojišťovnu ze
   seznamu, kód a název se doplní samy.
3. Vyplňte **Bankovní účet instituce** (předčíslí-číslo/kód banky, nebo IBAN),
   variabilní symbol zaměstnavatele (u pojišťovny číslo plátce, deset číslic),
   případně konstantní a specifický symbol.
4. Vyplňte **Platnost od**, zdroj ověření a **Ověřeno dne**. **Reference
   zdroje (volitelné)** může zůstat prázdná.
5. Klikněte na **Založit účet**.

Změnu bankovního účtu, typu, měny nebo počátku platnosti založte jako **nový**
účet s novou účinností a starému nastavte **Platnost do**.

**Jak poznáte, že je hotovo:** Účet je v seznamu **Platební účty institucí**
s celým číslem a variabilním symbolem v prvních sloupcích.

## 90.6 Krok za krokem: zákonné pojištění odpovědnosti

1. Na záložce **Účty institucí** založte účet typu Zákonné pojištění (postup
   v [§ 90.5](#905-krok-za-krokem-ucty-instituci)).
2. Níže v **Zákonné pojištění odpovědnosti zaměstnavatele** rozbalte sazebník
   přílohy č. 2 vyhlášky a klikněte na řádek své činnosti. Sazba se předvyplní.
3. Vyplňte **Platí od** a **Kód pojistitele** (stejný jako kód instituce
   u účtu Zákonné pojištění).
4. Klikněte na **Přidat sazbu**.

**Jak poznáte, že je hotovo:** Nad formulářem je věta „Aktuální sazba: … ‰,
platí od …".

## 90.7 Krok za krokem: automatické účtování

1. Otevřete záložku **Automatické účtování**.
2. U každé mzdové položky vyberte účet **Má dáti** a **Dal**. Nabídka obsahuje
   jen aktivní účty vhodného typu z vaší osnovy.
3. Zkontrolujte předkontace pro zvláštní situace (riziková práce, pohledávka
   za zaměstnancem, nedaňová část benefitu, cestovní náhrady, zákonné pojištění
   odpovědnosti).
4. Klikněte na **Uložit**.

**Jak poznáte, že je hotovo:** U žádného pole není červená hláška a aplikace
hlásí uložení.

> [!TIP]
> Přepnutí na analytiku `336.100` / `336.200` a `342.100` / `342.200` udělejte
> k začátku účetního období, ať se saldo neroztrhne uprostřed roku.

## 90.8 Krok za krokem: politiky a kontrola připravenosti

1. Otevřete záložku **Politiky a připravenost** a klikněte na **Nová politika**.
2. Vyplňte **Platnost od**, **Výplatní termín** (**Den výplaty**, **Měsíc
   výplaty**), **Posun pracovního dne**, **Zaokrouhlení zůstatku** a **Firemní
   výměra dovolené (týdny)**.
3. Případně nastavte **Výchozí sazby příplatků (§ 114–118)**, automatizaci
   (**Automaticky zaúčtovat**) a **Bezpečný kanál doručení** výplatnic.
4. Uložte politiku.
5. V **Kontrola připravenosti** zvolte **Kontrolovat ke dni** a projděte
   výsledek.

**Jak poznáte, že je hotovo:** Kontrola připravenosti ukazuje „V pořádku …
z … kontrol" bez blokujících nedostatků.

## 90.9 Krok za krokem: profil REGZEL a roční údaje pro JMHZ

**REGZEL profil zaměstnavatele** (jednou):

1. Otevřete záložku **Podání**.
2. Vyplňte **Kód finančního úřadu pro REGZEL** (čtyřmístný) a **Kód pracoviště
   finančního úřadu pro REGZEL**. Návrh z daňového nastavení firmy převezmete
   tlačítkem **Použít návrh … z daňového nastavení firmy**.
3. Jen má-li firma od správce daně přidělené, vyplňte **Vlastní číslo plátce
   (VČP)**.
4. Zaškrtněte postavení zaměstnavatele (**Agentura práce**, **Chráněný trh
   práce**, **Sociální podnik**), jen pokud ho máte doložené.
5. Zaškrtněte potvrzení správnosti a profil uložte.

**Roční údaje zaměstnavatele pro JMHZ** (před prosincovým hlášením):

1. Ve stejné záložce zvolte **Vykazovaný rok** a klikněte na **Načíst rok**.
2. Vyplňte **Průměrný roční počet zaměstnanců**, **Z toho průměrný počet
   zaměstnanců se zdravotním postižením**, **Forma vlastnictví a kontroly
   k 31. 12.** a **Typy kolektivních smluv k 31. 12.**
3. Případně omezte souhrn OZP na jednu účtárnu a uložte.

**Jak poznáte, že je hotovo:** U REGZEL profilu je **Naposledy potvrzeno**
s datem, u ročních údajů nová **Revize**.

## 90.10 Krok za krokem: import zaměstnanců z JMHZ a registrací

Použijte při přechodu z jiného programu, nebo když registraci podal někdo
jiný. Potřebujete oprávnění **Spravovat zaměstnance**.

1. Otevřete `Mzdy → Importy`, záložku **JMHZ**.
2. Nahrajte soubory XML najednou: přihlášky REGZEC a PREZEC, export
   zaměstnanců z ePortálu ČSSZ, měsíční hlášení JMHZ, případně NEMPRI, HZUPN
   a OZUSPOJ.
3. Projděte náhled. Každá věta ukazuje osobu, spárování a navrženou operaci.
   Zablokované věty mají důvod u sebe.
4. Nejasné formuláře přiřaďte ručně výběrem vztahu. U odhadnutého druhu vztahu
   zvolte **Druh vztahu podle smlouvy**.
5. Rozhodněte volby **Rovnou odškrtnout povinnosti ke změnám** a **Průměry
   rovnou schválit** (obě jsou předem zapnuté) a převzetí historie.
6. Potvrďte, že jste údaje porovnali s podáním přijatým ČSSZ, a klikněte na
   **Použít vybrané (…)**.
7. Ohlásí-li výsledek **Import není úplný**, klikněte na **Ukázat v náhledu**,
   opravte formulář a použijte znovu.

**Jak poznáte, že je hotovo:** Výsledek nehlásí neúplný import, osoby a vztahy
jsou v `Mzdy → Zaměstnanci` a u vztahů je OIČ a ID PPV.

## 90.11 Krok za krokem: měsíční import docházky

Potřebujete oprávnění k zápisu mzdových vstupů.

1. Otevřete `Mzdy → Importy`, záložku **Docházka**.
2. Zvolte měsíc mezd a nahrajte všechny soubory (XLSX, CSV) najednou. Profil
   mapování se vybere sám; jinak ho zvolte ručně nebo otevřete **Upravit
   mapování**.
3. V kroku osob zkontrolujte spárování. Nejasné osoby přiřaďte ručně,
   chybějící osoby založte tlačítkem.
4. V souhrnu zkontrolujte hodiny a částky. Rozhodněte **Převzít měsíční mzdu
   z podkladů** a **Založit srážky ze mzdy**.
5. Potvrďte případný nález o jiném měsíci nebo o dvojích vstupech a klikněte
   na **Použít (… osob)**.
6. Návrhy mzdových vstupů schvalte před výpočtem běhu.
7. Po výpočtu běhu můžete v historii importů kliknout na **Porovnat s výpočtem
   mezd**.

**Jak poznáte, že je hotovo:** V historii importů je dávka za měsíc a v mzdových
vstupech jsou návrhy z ní.

## 90.12 Krok za krokem: OIČ z POHODY

Potřebujete oprávnění spravovat zaměstnance i pracovní vztahy.

1. V POHODĚ vyexportujte **Tabulka agendy Personalistika** do XLSX (nebo CSV se
   stejnými sloupci).
2. Otevřete `Mzdy → Importy`, záložku **OIČ z POHODY**, a soubor nahrajte.
3. Projděte stavy řádků. Vybrat jdou jen řádky **Připraveno**.
4. Zvolte prostředí (ostré nebo testovací), potvrďte, že jste čísla ověřili
   proti ePortálu ČSSZ nebo registracím, a import použijte.

**Jak poznáte, že je hotovo:** Opakovaný náhled ukáže u řádků **Už uloženo**.

## 90.13 Když něco nejde

<!-- cols: 30 34 36 -->
| Co vidíte | Proč | Co udělat |
|---|---|---|
| „Před uložením opravte označená pole." | Chybí aktivní účtárna, výchozí účtárna, platný kód OSSZ, pojišťovna, e-mail nebo účet | Opravte pole označená červeně a uložte znovu. |
| „Kód správy sociálního zabezpečení musí být trojmístné číslo" | Kód není trojmístný, nebo není v číselníku okresních správ | Opište kód z potvrzení o registraci, nebo pole nechte prázdné. |
| Tlačítko **Uložit** v dialogu registrace je neaktivní | Variabilní symbol nemá přesně deset číslic | Opište celý desetimístný symbol. |
| „Datum účinnosti musí navazovat za poslední uloženou verzi." | Nová verze začíná dřív než poslední | Nejnovější záznam **Vzít zpět** a zadejte znovu. |
| Uložení nebo příprava přihlášky či hlášení JMHZ hlásí, že variabilní symbol ČSSZ není platný | Nesouhlasí kontrolní číslice, nebo symbol nezačíná kódem okresní správy | Opravte symbol v **Spravovat účinné registrace** podle oznámení ČSSZ ([§ 90.14.1](#90141-mzdove-uctarny-a-registrace-u-cssz)). |
| Mzdový běh za dřívější měsíce neprojde kvůli registraci účtárny | Historie registrace začíná později, nebo chybí | Vezměte poslední verzi zpět a zadejte ji se skutečným datem registrace. |
| Testovací podání ČSSZ hlásí chybějící pověření nebo certifikát | Odešlo pod ostrým symbolem | Vyplňte **Testovací VS ČSSZ**. |
| „Pro tuto instituci už ve zvoleném období existuje účet." | Překrývající se období účtů téže instituce a měny | Starému účtu nastavte **Platnost do**. |
| Účet instituce nejde smazat | Z účtu se už platilo | Nastavte mu konec platnosti. |
| „Sazba zákonného pojištění odpovědnosti není nastavena" | Chybí sazba | Přidejte ji ([§ 90.6](#906-krok-za-krokem-zakonne-pojisteni-odpovednosti)). |
| „Nastavení se mezitím změnilo" | Jiný uživatel uložil novější verzi | Klikněte na **Načíst aktuální data** a změny zadejte znovu. |
| Import JMHZ zablokuje větu jako jiného zaměstnavatele | VS ve větě nesouhlasí s VS vašich účtáren | Zkontrolujte, že nahráváte soubory své firmy. |
| Import docházky hlásí jiný měsíc nebo dvojí vstupy | Soubor patří jinému měsíci, nebo období už má vstupy z jiného importu | Zkontrolujte období; vědomě pokračujte potvrzením nálezu. |

## 90.14 Podrobnosti a pravidla

### 90.14.1 Mzdové účtárny a registrace u ČSSZ

Firma může mít víc mzdových účtáren, právě jedna aktivní je výchozí. Každá má
název, kód a vlastní variabilní symbol pro platby sociálního pojištění. Kód se
předvyplní z názvu (bez diakritiky, velkými písmeny) a při shodě dostane
číselnou příponu; jakmile do něj sáhnete, z názvu se přestane odvozovat. Stejně
se chová kód mzdové složky, dimenze i ručně zadané instituce. **Registrační
číslo zaměstnavatele** slouží pro evidenci a podání, není variabilním symbolem
platby. **Kód správy sociálního zabezpečení** je trojmístný kód pracoviště ČSSZ
(např. 110 pro Prahu 10) z potvrzení o registraci; je nepovinný a při uložení se
ověří proti číselníku okresních správ.

**Variabilní symbol ČSSZ** se nezadává přímo do řádku účtárny, ale jako účinná
historie registrace (**Spravovat účinné registrace**). Každé uložení je neměnná
verze s datem účinnosti; novější historii nelze zpětně přepsat. Vzít zpět jde
jen nejnovější verzi, a jen dokud se o ni nic neopírá. Bez historie registrace
budou nové přípravy blokované. Výchozí datum je začátek vedení mezd v MyÚčtu:
symbol přiděluje ČSSZ při registraci, ne v den, kdy ho opíšete, a dnešní datum by
zablokovalo mzdy za předchozí měsíce.

**Kontrola symbolu.** Logická kontrola ČSSZ (katalog kontrol MH č. 143, EDV ID
10221 a 10222) chce 8 až 10 číslic. Desetimístný symbol musí projít Luhnovým
součtem přes všech deset číslic a, není-li zahraniční (začíná `1868`), začínat
kódem okresní správy z číselníku okresů ČSSZ. Kratší, dříve přidělené symboly
kontrolu součtu ani okresu nemají. Aplikace tuto kontrolu provádí při přípravě
přihlášek PREZEC a REGZEC a při změně variabilního symbolu (A5); hláška řekne,
zda nesouhlasí kontrolní číslice, nebo první tři číslice nejsou kódem okresu.
Stejnou kontrolu dělá i uložení nového nebo změněného symbolu (ostrého
i testovacího) a příprava měsíčního hlášení JMHZ.

**Testovací VS ČSSZ.** Testovací prostředí ČSSZ má vlastní přidělený symbol
a podání pod cizím symbolem zamítne. Odmítnutí přitom hlásí chybějící pověření
k e-službě nebo nezaznamenaný certifikát, takže se snadno splete s problémem
podpisu. Vyplněný testovací symbol přehled odeslání v testovacím prostředí
nabídne sám a při odesílání pod ostrým symbolem upozorní. Všechna podání ČSSZ
pro test (JMHZ, PREZEC, REGZEC, NEMPRI, HZUPN, OZUSPOJ) nesou testovací symbol;
bez něj zůstává symbol účtárny. Do ostrého prostředí testovací symbol nikdy
neodejde.

**Převzetí z Nastavení firmy.** Právnická osoba, která vedla variabilní symbol
ČSSZ, kód OSSZ nebo číslo plátce zdravotního pojištění v Nastavení firmy, o ně
zapnutím mezd nepřijde. Údaje se přenesou (ručním zapnutím mezd, převodem mezd
z jiného programu i prvním uložením nastavení zaměstnavatele): variabilní symbol
k výchozí účtárně, kód OSSZ do nastavení zaměstnavatele a číslo plátce jako
variabilní symbol účinného účtu výchozí pojišťovny v **Účtech institucí** (i účtu
založeného později). Přenáší se jen do prázdného pole. Variabilní symbol a číslo
plátce jen jako 1 až 10 číslic, kód OSSZ jen jako trojmístné číslo. Z Nastavení
firmy se údaj odstraní, až když ho mzdy opravdu drží. Převod mezd z jiného
programu založí k desetimístnému symbolu i záznam v historii registrace
s účinností od začátku vedení mezd v MyÚčtu; skutečné datum opravíte tak, že
nejnovější záznam vezmete zpět a zadáte znovu. Při ručním zapnutí mezd se datum
nevymýšlí, doplňte ho sami, jinak mzdový běh za období neprojde.

Osobní variabilní symbol ČSSZ a číslo pojištěnce OSVČ v obecném nastavení firmy
slouží jen pro odvody fyzické osoby; platby zaměstnavatele je nepřebírají.
U právnické osoby se tato pole v obecném nastavení nezobrazují. Automatické
návrhy a rozpoznání bankovních plateb používají aktivní účtárnu a účet
instituce platný k datu platby; nejednoznačný nebo historický údaj zůstane
k ručnímu posouzení.

### 90.14.2 REGZEL profil zaměstnavatele

Profil je samostatný evidenční podklad pro REGZELDOPL25 a každé uložení
vyžaduje nové výslovné potvrzení. Obsahuje čtyřmístný kód finančního úřadu
(`kodFU`, z číselníku; nejde o tříčíselný kód EPO ani kód pracoviště), povinný
kód územního pracoviště (`kodPracovisteFU`; prázdný jen u Specializovaného
finančního úřadu 4000 a musí patřit pod zadaný úřad), případné devítimístné VČP
začínající `6` a tři příznaky zaměstnavatele. Kód pracoviště může aplikace
nabídnout z daňového nastavení firmy, ale použije ho až po potvrzení; `kodFU`
nikdy neodvozuje. VČP vyplňte, jen když ho firmě přidělil správce daně; nejde
o registrační číslo zaměstnavatele ani o variabilní symbol ČSSZ. Postup podání
REGZEL je v [§ 85.14.15](85_Podani_a_hlaseni.md#851415-registrace-zamestnavatele-regzel).

**Roční údaje zaměstnavatele pro JMHZ** (údaje 10214, 10220 a roční souhrn OZP)
se připojí k prosincovému podání. Bez výběru účtárny vznikne část OZP za všechny
registrované účtárny. Každé uložení vytváří dohledatelnou revizi.

### 90.14.3 Platební účty institucí

Sekce **Platební účty institucí** eviduje účty ČSSZ, finančního úřadu,
zdravotních pojišťoven, zákonného pojištění a dalších příjemců. U účtu je typ
instituce, kód (pod ním ho hledá příprava plateb), variabilní symbol
zaměstnavatele, měna, období platnosti, druh ověření a datum ověření. Reference
zdroje je nepovinná, stejně jako reference v politikách a u převzatých
počátečních stavů. Povinná pole mají hvězdičku. Účet se v seznamu zobrazuje
celý, v úložišti zůstává šifrovaný.

Bankovní účet, typ instituce, měna a počátek účinnosti jsou neměnné; jejich
změnu založte jako nový historický řádek. U existujícího záznamu lze upravit
název, platební symboly, konec platnosti a ověření. Kód instituce opravit lze,
dokud na účet neodkazuje závazek čekající na platbu. Období stejné instituce
a měny se nesmějí překrývat. Smazat jde jen účet, ze kterého se nikdy
neplatilo.

### 90.14.4 Zákonné pojištění odpovědnosti zaměstnavatele

Sazba podle vyhlášky č. 125/1993 Sb. se ukládá s datem platnosti a kódem
pojistitele, který musí odpovídat kódu instituce u účtu typu Zákonné pojištění.
Sazbu určuje a pojistné počítá i platí sám zaměstnavatel (§ 12 odst. 2
vyhlášky); pojišťovna neposílá výměr ani předpis.

Sazebník přílohy č. 2 obsahuje všech osm sazbových skupin a 98 vyjmenovaných
činností, včetně dvou skupin bez kódu: 10,5 ‰ pro činnosti s výbušninami,
radioaktivními látkami, radonem, infekčním materiálem nebo jedy a práci ve
velkých výškách či hloubkách, a 5,6 ‰ pro ostatní ekonomické činnosti. Kliknutím
na řádek se sazba předvyplní; uloží se až tlačítkem **Přidat sazbu** a předtím
ji lze přepsat.

Sazebník je **podklad, ne odpověď**. Příloha č. 2 člení činnosti podle OKEČ,
kterou ČSÚ zrušil k 31. 12. 2007 a nahradil CZ-NACE; vyhláška se nezměnila, takže
závazná je stále OKEČ. Stejné číslo znamená v obou klasifikacích jinou činnost
(OKEČ 62 je letecká doprava, CZ-NACE 62 činnosti v oblasti IT) a závazný
převodník neexistuje. MyÚčto proto kódy nepáruje: s vyplněným CZ-NACE nabídne
řádky podobné **názvem** jako nezávazný návrh. Rozhoduje skutečná převažující
základní činnost.

Sazbu mimo přílohu č. 2 (50,4 / 10,5 / 9,8 / 8,4 / 7 / 5,6 / 4,2 / 2,8 ‰)
formulář ohlásí, ale uložení nezablokuje; doložená odlišná sazba má přednost.
Z uložené sazby se čtvrtletně počítá pojistné z vyměřovacího základu sociálního
pojištění, nejméně 100 Kč za čtvrtletí, zaokrouhleno nahoru na koruny. Závazek
vznikne při přípravě plateb za poslední měsíc čtvrtletí. V podvojném účetnictví
se zaúčtuje i předpis k poslednímu dni čtvrtletí (výchozí 548 / 379.400, případně
379 bez analytiky v osnově). Opravná revize předepíše jen rozdíl. Úhrada
spárovaná se závazkem se účtuje proti účtu předpisu. Zamčené účetní období
předpis přeskočí a další příprava plateb ho doplní.

### 90.14.5 Automatické účtování

Výchozí účty se rozlišují pro mzdu zaměstnance mimo výkon funkce, příjem
společníka a odměnu za výkon funkce člena orgánu (viz
[§ 86.12.7](86_Zamestnanci.md#86127-druh-vztahu-a-predkontace)), dále pro
pojistné, daň a ostatní srážky. Nabídka obsahuje jen aktivní účty vhodného typu.
Příznak automatického zaúčtování se při uzamčení vstupů uloží do neměnné
revize běhu. Je-li pro období vypnutý, schválení deník nevytvoří; vypnutí
neznamená, že se neúčtuje, zaúčtování schválené revize jen vyvoláte sami.
Pozdější změna politiky uzamčený běh nezmění; chybějící nebo neplatná politika
automatické účtování bezpečně zastaví.

**Analytika pojistného a daně.** Pole účtu přijme i analytiku. U **nově založené
firmy** se předvyplní `336.100` (sociální), `336.200` (zdravotní), `342.100`
(zálohová daň) a `342.200` (srážková daň). Firmám se syntetickými `336` a `342`
je aplikace **sama nepřepíše**, protože přepnutí uprostřed roku by rozdělilo
saldo. Chcete-li rozpad, přepněte účty sami, ověřte analytiky v osnově
a udělejte to k začátku účetního období.

Nové předkontace se do zaúčtovaných revizí nepromítají. Zmrazený snímek nese
vlastní sadu účtů, takže opakované zaúčtování staršího období vypadá přesně jako
poprvé.

### 90.14.6 Předkontace pro zvláštní mzdové situace

Tyto předkontace se použijí jen v konkrétní situaci. Nevyplněná předkontace
použije bezpečnou výchozí hodnotu; vyplňte ji tam, kde se vaše osnova liší.

<!-- cols: 30 44 26 -->
| Předkontace | Kdy se použije | Výchozí účty |
|---|---|---|
| **Povinné spoření u rizikové práce** | zákonný příspěvek zaměstnavatele a závazek vůči penzijní společnosti | 527 / 379 |
| **Pohledávka za zaměstnancem** | záporná čistá mzda | 335 proti 331 nebo 366 |
| **Nedaňová část benefitu** | osvobozená část nepeněžního benefitu | 528 |
| **Cestovní náhrady** | vyúčtování pracovní cesty promítnuté do mzdy | 512 proti 331 nebo 366 |
| **Zákonné pojištění odpovědnosti** | čtvrtletní předpis pojistného podle vyhlášky č. 125/1993 Sb. | 548 / 379.400 |

Příspěvek na spoření u rizikové práce se zaměstnanci nevyplácí a penzijní
společnost není institucí sociálního ani zdravotního pojištění, proto nejde na
331 ani na 336.

Nedaňová část benefitu je ta, která je **u zaměstnance osvobozená** od daně;
§ 25 odst. 1 písm. h) zákona o daních z příjmů ve znění od 1. 1. 2024 ji vylučuje
z daňově uznatelných nákladů. Nadlimitní část se zaměstnanci zdaní
a zaměstnavateli uznatelná zůstává (§ 24 odst. 2 písm. j) bod 4). Dělení se týká
**jen** košů **zdravotní plnění** a **rekreace, sport a kultura** podle § 6
odst. 9 písm. d); stravování, spoření na stáří a přechodné ubytování jsou
uznatelné celé (viz [Koše benefitů](89_Kose_benefitu.md)).

Cestovní náhrady se účtují proti závazkovému účtu pracovního vztahu, ne na
samostatný účet jiných závazků. Zaměstnanci se vyplácí totéž, mění se jen zápis
v deníku.

### 90.14.7 Politiky a připravenost

Záložka vede časovou historii výplatního dne, pravidla posunu na pracovní den,
zaokrouhlení doplatku, firemní výměry dovolené (4 až 12 týdnů; běžně 5,
výjimku lze nastavit na vztahu), výchozích sazeb příplatků, oprávnění účetní,
automatických kroků a bezpečného doručení. Výchozí sazby příplatků (§ 114–118
zákoníku práce) platí pro vztahy bez vlastního sjednání; prázdné pole znamená
zákonné minimum, které se u pevné částky hlídá až při výpočtu z průměru
konkrétního člověka. Procento smí být nejvýš 500 %, pevná částka nejvýš
1 000 Kč za hodinu. Šifrovaný e-mail mzdové dokumenty zatím neodesílá, s tímto
kanálem politiku uložit nelze; bez data ověření kanálu se politika uloží, ale
výplatnice se jím neodešlou.

Jedna oprávněná účetní může celý mzdový tok dokončit a odeslat bez zásahu druhé
osoby. Výpočet a přípravu plateb spouští účetní vždy ručně. Období dvou politik
se nesmějí překrývat, novou budoucí politiku založte až po ukončení předchozí.
Změna auditovaného záznamu zvýší jeho verzi. Původ systémového nebo migrovaného
záznamu nelze ručně změnit.

**Kontrola připravenosti** se spouští k vybranému dni a ukazuje každý ověřený
předpoklad i přesný blokující nedostatek. Kontrolují se jen funkce, které firma
zapnula; zapnutá automatizace, JMHZ nebo bezpečné doručení ale bez pozitivního
důkazu zůstávají zablokované.

Po dokončení základního nastavení se mzdový modul sám označí jako aktivní.
Nedokládají se paralelní měsíce, opravný běh, obnova ze zálohy ani kvalifikační
protokol; od aktivace jsou dostupná ostrá podání i mzdové platební příkazy
(viz [úvodní kapitola mezd](75_Uplne_mzdy.md#753-krok-za-krokem-prvni-nastaveni-mezd)).

Změny se ukládají s kontrolou souběžné editace. Změnil-li nastavení mezitím
jiný uživatel, aplikace ukáže důvod konfliktu. **Načíst aktuální data** obnoví
i číslo verze a teprve pak lze uložit znovu.

### 90.14.8 Dimenze

Záložka **Dimenze** vede mzdová střediska, zakázky a činnosti, vlastní číselník
nezávislý na účetním rozvrhu, takže funguje i v daňové evidenci. Dimenze má
typ, kód, název, období účinnosti a volitelný výchozí analytický účet pro
předkontace. Kód je unikátní v rámci typu s ohledem na účinnost; v nepřekrývajícím
se pozdějším období ho lze použít znovu. Dimenzi použitou ve schválené revizi
nejde smazat, jen ukončit; nepoužitou smažete běžně.

Mzdovou dimenzi lze v poli **Firemní dimenze** navázat na hodnotu z `Firma →
Dimenze`. Mzdy se pak účtují na stejné středisko jako faktury a řádky mzdového
předpisu v deníku nesou firemní dimenzi. U nové mzdové dimenze výběr firemní
hodnoty předvyplní typ, název a kód. Bez vazby se mzdy účtují jen s textovým
kódem střediska.

Přiřazení pracovnímu vztahu se vede na kartě vztahu s vlastní účinností a bez
souběhu dvou dimenzí stejného typu. Vztah pro víc středisek rozdělíte tlačítkem
**Rozdělit podílem**: typ, hodnoty a procenta (např. 70 % a 30 %), součet přesně
100 %. Rozpad platí od zvoleného dne a dosavadní přiřazení téhož typu se den
předtím ukončí. Rozdělení nákladů v deníku popisuje [Dimenze](114_Dimenze.md),
část Mzdy.

Výchozí účet dimenze mění jen nákladovou stranu hrubé mzdy a použije se, jen
když mzdová složka nemá vlastní předkontaci. Má-li vztah účet na víc dimenzích,
rozhoduje pořadí středisko, zakázka, činnost. Přiřazení i účet se při uzamčení
vstupů uloží do snímku revize, takže pozdější změna schválený měsíc nezmění.
Účty vyhrazené pro pojistné, daň, srážky nebo čistou mzdu (např. 524, 336, 342,
379, 331 a 366) aplikace jako nákladový účet odmítne.

### 90.14.9 Skrytá varování

Záložka **Skrytá varování** ukazuje varování, která jste u mzdového běhu trvale
skryli (u osoby, nebo **Celý typ ve firmě**), kdo a kdy je skryl. Do kontrol
běhu ani jejich počtů se nepočítají, dokud je neobnovíte tlačítkem **Obnovit**
nebo **Obnovit vše**. Blokující chyby skrýt nejde. Skrývat a obnovovat smí jen
uživatel s právem schvalovat mzdové běhy.

### 90.14.10 Import JMHZ: registrace a měsíční hlášení

`Mzdy → Importy` leží v menu za Nastavením mezd a slouží k převzetí dat při
zavádění mezd i v běžném měsíci. Záložky **JMHZ**, **Docházka**, **Mapování
sloupců** a **OIČ z POHODY** pracují stejně: nahrajete soubory, prohlédnete
náhled a teprve tlačítkem **Použít** se něco zapíše. Soubory se na serveru
neukládají, při použití se náhled spočítá znovu. Záložky přechodu z jiného
programu (**Převzaté mzdy**, **Kontrola převzetí**, **Kontace z převzetí**) se
nabízejí jen při převodu a popisují je kapitoly o přechodu (např.
[Přechod z PAMICA](108_Prechod_z_PAMICA.md)).

Záložka **JMHZ** načte XML registrací (REGZEC, PREZEC), export zaměstnanců
z ePortálu ČSSZ, měsíční hlášení JMHZ a podání NEMPRI, HZUPN a OZUSPOJ odeslaná
předchozím programem. Soubory může vytvořit i jiný program. Vyžaduje oprávnění
`payroll.person.write`. Soubor XML smí mít nejvýš 20 MB (celá dávka 20 MB), takže
se vejde i balík JMHZ s tisíci formuláři.

Náhled ukáže každou větu zvlášť: druh akce, osobu, maskované rodné číslo, nástup
nebo skončení, stav spárování a navrženou operaci. Osoba se hledá podle OIČ,
rodného čísla a u vztahu podle ID zaměstnání. Aplikace navrhne:

- **založení osoby** se vztahem, identitou, adresou a údaji pro ČSSZ; u nástupu,
  který už nastal, vztah rovnou aktivuje,
- **nový pracovní vztah** u evidované osoby,
- **aktualizaci** rozdílných údajů (pojišťovna, adresa, titul, místo narození,
  občanství, pracoviště, CZ-ISCO, druh činnosti); změnu jména jen oznámí,
- **doplnění chybějících údajů**: rodné číslo (u cizince EČP), datum narození,
  daňová rezidence a zahraniční daňový identifikátor. Zástupné jméno „Doplňte"
  nahradí věta se jménem, datum nástupu z REGZEC posune odhadnutý nástup.
  Daňová rezidence se zapíše od nástupu a jen tam, kde žádná ověřená není,
- **profil registrace REGZEC A1** vztahu: postavení v zaměstnání, pracovní
  režim, nepřetržitý provoz, název a vedoucí pozice, vzdělání, důchod, cizí
  předpisy a adresy po složkách. Profil vztahu, za který registraci podala
  aplikace, import nemění,
- **ukončení vztahu** u odhlášení, případně zápis „nenastoupil",
- **doplnění OIČ a ID zaměstnání**, pokud je věta nese. Platí od začátku vztahu,
  ne od účinnosti věty.

Věty bez jednoznačného přiřazení nejde vybrat. Identifikátory se ukládají jako
ověřený ruční opis. Opakovaný import téhož souboru nic nezaloží podruhé.

#### Kontroly vět REGZEC a PREZEC

- **Schéma ČSSZ.** Soubor se ověří proti REGZEC25, resp. PREZEC26. Vadné věty
  (např. stát adresy „Čes" místo „CZ") se nepřevezmou a náhled je vypíše
  s důvodem; ostatní věty jdou dál. Soubor s jedinou větou, se všemi větami
  vadnými nebo s vadou mimo věty se odmítne celý.
- **Zaměstnavatel.** Variabilní symbol věty (u změny VS starý i nový) se
  porovná se symboly vašich účtáren; věta jiného zaměstnavatele je zablokovaná.
  Bez vyplněného symbolu jde věta použít s upozorněním.
- **Souběžný vztah.** Přihláška A1 s jiným ID zaměstnání, než má aktivní vztah,
  založí druhý vztah.
- **Změna podmínek.** Změna z věty se nezapíše zpětně; platí od prvního dne
  měsíce účinnosti jako nová verze podmínek a povinnosti ke změně vzniknou jako
  u ruční změny. Je-li od toho měsíce zaúčtovaná nebo vyplacená mzda, změna se
  nezapíše a výsledek to ohlásí.
- **Starší věty než evidence.** Věta, po jejímž dni se pojišťovna nebo adresa
  změnila, tyto údaje nepřepíše; náhled napíše, že věta je starší než evidence.
- **Přihláška a nenastoupení v jedné dávce.** A1 a A8 téže osoby založí vztah
  jako plánovaný a nenastoupení ho uzavře; totéž PREZEC a ukončení
  předregistrace. Nezapíše-li se nenastoupení, výsledek je **Import není
  úplný** a vztah zůstane plánovaný.
- **Bez příznaku zaměstnání malého rozsahu.** Odhláška, změna nebo nenastoupení
  s kódem činnosti 1 až 9 bez příznaku najde pracovní poměr i zaměstnání malého
  rozsahu; víc shod větu zablokuje jako nejednoznačnou.
- **Bližší určení vztahu.** Evidence vede u pracovního poměru jen určení 1;
  věta s určením 2 až 9 se zapíše jako 1 a náhled upozorní.
- **Rodné číslo.** Osoba nalezená podle OIČ, ID zaměstnání nebo VČP s jiným
  rodným číslem (EČP) dostane upozornění; vedené číslo se nepřepisuje.
- **VČP** (devět číslic začínajících šestkou) slouží k nalezení osoby a zapíše
  se, kde chybí.
- **Dřívější příjmení** z věty není rodné příjmení. Import ho nezapisuje, protože
  věta nenese, od kdy do kdy příjmení platilo, a historie jména tyto údaje
  vyžaduje. Vede-li historie jména příjmení už, náhled mlčí; jinak upozorní,
  které příjmení doplnit (karta osoby, Historie jména, s datem změny). Doplněné
  příjmení pak nese i další přihláška a dohlášení.
- **Skončení úmrtím** zapíše u vztahu způsob skončení *úmrtí*, pokud není
  vyplněný. Kód důvodu ukončení pro úřad práce se nepřebírá, náhled na něj
  upozorní; způsob a důvod skončení doplňte na kartě vztahu.

#### Export zaměstnanců z ePortálu ČSSZ

Přehled zaměstnanců z ePortálu (kořen `ExportZamestnancu`) se v náhledu zobrazí
jako **Export zaměstnanců ČSSZ**. Import zná oba tvary se zveřejněným schématem
(dosavadní a platný od 15. 10. 2026, který nese začátek pojistného vztahu) a
soubor proti schématu ověří; nevyhovující soubor nepřevezme. Věta nese jméno,
rodné číslo (u cizince EČP), OIČ, ID zaměstnání, druh činnosti, příznak
zaměstnání malého rozsahu, variabilní symbol, případně konec pojistného vztahu;
nový tvar i začátek pojistného vztahu a bližší určení činnosti.

- **Nástup** je začátek pojistného vztahu z nového tvaru, kromě zaměstnání
  malého rozsahu a DPP (pojištěné jen v měsících s rozhodným příjmem). U nich
  (a u dosavadního tvaru) se vezme z hlášení v dávce: nejdřívější datum nástupu
  z formulářů, jinak nejdřívější začátek pojištění v hlášeném měsíci. Vyjde-li
  na první den nejstaršího měsíce, náhled upozorní, že pojištění mohlo začít
  dřív. Skutečný nástup ověřte podle smlouvy a případně opravte na kartě vztahu.
- **Konec pojistného vztahu** nový vztah k tomu dni ukončí. U trvajícího
  evidovaného vztahu nabídne zaškrtávátko **Ukončit vztah k …**; po zaškrtnutí
  se náhled přepočítá, u věty přibude změna Skončení vztahu a při použití se
  vztah ukončí jako na kartě vztahu. Důvod skončení a další podklady doplňte na
  kartě vztahu v části Skončení vztahu. Bez zaškrtnutí zůstane jen upozornění.
  Budoucí datum nebo datum před nástupem ukončit nejde.
- **EČP** slouží k nalezení osoby bez rodného čísla a zapíše se při založení.
- U evidované osoby import doplní chybějící OIČ a ID zaměstnání. Druh činnosti
  a druh vztahu jen porovná, nesoulad ohlásí varováním a podmínky vztahu nemění.
  Naplánovaný vztah aktivuje (ID zaměstnání dokládá přihlášení), s budoucím
  nástupem ho nechá naplánovaný.
- Neevidovanou osobu založí, když export nese začátek pojistného vztahu, nebo
  když dávka obsahuje hlášení se stejným ID zaměstnání. Jinak je věta
  zablokovaná; nahrajte k exportu hlášení nebo přihlášku REGZEC.
- Nesouhlasí-li variabilní symbol, věta je zablokovaná jako export jiného
  zaměstnavatele; bez symbolu u účtáren se kontrola přeskočí.

Věty exportu se zapíšou dřív než formuláře hlášení, takže se formuláře
k novým vztahům spárují samy. Vyberte proto obojí najednou.

#### Měsíční hlášení z předchozího programu

Hlášení se přebírají jen od vlastního zaměstnavatele podle variabilního symbolu;
bez symbolu u účtáren projde jen dávka jediného zaměstnavatele s upozorněním.
Formulář se páruje podle ID zaměstnání, OIČ a bez identifikátorů podle jména
a data narození. Větu bez shody přiřadíte ručně výběrem vztahu a náhled se
přepočítá. Vztah s jiným ID zaměstnání je jiný vztah: jako shoda se nenabízí
a ruční přiřazení k němu import odmítne (převzaté měsíce formuláře by jinak
přepsaly jeho vlastní). Z formuláře se převezme pracoviště, úvazek podle fondu,
prohlášení poplatníka, slevy a vyživované děti podle období, ve kterém platily.
Opravné a stornovací podání se skládá s řádným podle pořadí.

**Souběh.** Nese-li formulář OIČ evidované osoby, ale ID zaměstnání, které
žádný její vztah nemá, jde o další souběžný vztah, typicky dohodu vedle
pracovního poměru. Náhled ukáže **Založit vztah** a vztah (vedlejší, ne hlavní)
založí věta **Odvozeno z hlášení JMHZ**. Vyberte obojí najednou, formuláře se
k novému vztahu spárují samy.

**Zaměstnanci doložení jen hlášením.** Bez registrace i exportu nabídne náhled
větu **Odvozeno z hlášení JMHZ**:

- nástup z formuláře, jinak z exportu v dávce, jinak začátek pojištění
  v prvním měsíci; bez dřívějších měsíců je to odhad označený **Nástup
  odhadnutý** a výsledek nabídne odkaz na kartu osoby, kde nástup doplníte ze
  smlouvy (pracovní vztah, Sjednané podmínky),
- druh vztahu z druhu činnosti, jinak z kódu ELDP. Formulář bez obojího, který
  ve všech měsících nemá vyměřovací základ ani stanovenou týdenní pracovní dobu
  (hodnota 99) a nese příjem z nepojištěné činnosti, je DPP; nedosáhl-li příjem
  v žádném měsíci rozhodného příjmu zaměstnání malého rozsahu (4 500 Kč), může
  jít i o DPČ malého rozsahu a věta nabídne **Druh vztahu podle smlouvy**. Jiný
  vztah bez ELDP import nezaloží, založte ho ručně,
- formulář se jménem a datem narození založí osobu pod jménem, jen s OIČ a ID
  zaměstnání se zástupným jménem **Doplňte**. Jméno, rodné číslo, adresu
  a pojišťovnu pak doplňte na kartě osoby, nebo nahrajte export zaměstnanců
  z ePortálu ČSSZ.

**Dřívější nástup z pozdější dávky.** Hlášení za starší měsíce nabídnou změnu
**Nástup** na doložený den. Nástup se posune všude (vztah, první verze podmínek,
aktivace, identifikátory, nejstarší údaje osoby), jen dřív a jen bez zaúčtované
nebo vyplacené mzdy v posunutém období.

**Převzaté mzdy.** Z hlášení za měsíce před vedením mezd v MyÚčtu import
převezme historii stejně jako převod z PAMICA nebo PREMIER: úhrny vztahu
a měsíce (hrubé příjmy odpovídají úhrnu zúčtovaných příjmů, čistá mzda, zálohy
a srážková daň, pojistné, vyměřovací základ, dny pojištění, hodiny), ze kterých
čte kontrolní sestava převodu, převzatý běh, návrh průměrného výdělku a hlídání
ročního limitu DPP; dále sjednanou měsíční mzdu a její předpis (když tarif
v měsících bez dovolené a nemoci zůstává stejný i při různém fondu), průměrný
výdělek po čtvrtletích jako schválený průměr, čerpání dovolené po měsících
a skončení vztahu (když pojištění končí před koncem měsíce nebo vztah v řádném
hlášení dalšího měsíce chybí). Příspěvky zaměstnavatele na penzijní
připojištění, doplňkové penzijní spoření, penzijní a životní pojištění a DIP
(z formuláře se souhrnnými daty) čerpají roční limit osvobození, takže další
měsíce téhož roku osvobodí jen zbytek. Mzdový list, potvrzení o příjmech, roční
zúčtování a vyúčtování daně čtou počáteční stavy ročních součtů, ne převzaté
mzdy. Upozornění na chybějící záměr slevy zaměstnavatele jmenuje důvod
uplatnění slevy, který hlášení vykázalo.

Účast na důchodovém pojištění dokládá kód ELDP nebo nenulový vyměřovací základ:
měsíc celý v dávkách (mateřská, dlouhá nemoc) je dobou účasti i s nula dny
a pracující důchodce, za kterého se ELDP nehlásí, má dny účasti podle trvání
pojištění v měsíci. Vyloučené dny se převezmou z úhrnu, jinak z podpoložek.
Druh činnosti, který hlášení nenese, se doplní ze sjednaných podmínek vztahu.
Zdravotní vyměřovací základ a srážky hlášení nenese, zůstávají nulové. Měsíc
převzatý z jiného zdroje se nepřebírá a opakovaný import nic nezdvojí. Částečně
převzatý měsíc je označený **Převezme se částečně** se seznamem chybějících
formulářů.

**Neúplný import.** Zůstane-li platný formulář bez vztahu nebo zablokovaný,
případně selže některá věta, výsledek ohlásí **Import není úplný** se seznamem
a důvodem; zapsané věty zůstávají. **Ukázat v náhledu** přejde k formuláři,
který opravíte (přiřadíte vztah, vyberete větu zakládající vztah) a použijete
znovu. Vztah bez odpracovaných hodin v posledních měsících náhled doporučí
evidovat jako dlouhodobou nepřítomnost (mateřská, rodičovská).

Z historie hlášení aplikace navrhne i **počáteční stavy ročních součtů**
(základy, zálohy, slevy a bonus po měsících) pro roční zúčtování a limity při
přechodu během roku a **průměrné výdělky** po čtvrtletích, které se zakládají ke
schválení v Nepřítomnostech. Návrh s chybějícími údaji nebo už evidovaný se
nepoužije. Převzetí historie zapnete zaškrtnutím před **Použít**.

Volby automatického schválení jsou předem zapnuté a jde je vypnout:

- **Rovnou odškrtnout povinnosti ke změnám**: nová verze podmínek jinak založí
  na kartě vztahu úkoly (dodatek smlouvy, oznámení změny pojišťovně a ČSSZ).
  Změnu už vykázalo importované podání, proto je import odškrtne s poznámkou.
  Povinnosti při nástupu a skončení i starší rozpracované úkoly zůstávají.
- **Průměry rovnou schválit**: bez této volby čekají založené průměry na
  schválení v Nepřítomnostech.

#### Podání dávek a záměrů slevy předchozího programu

NEMPRI (formát 2025 i starší 2020 z Money S3), HZUPN a OZUSPOJ se znovu
neodesílají; import zapíše, že je vyřídil předchozí program, aby je hlídač
termínů nepožadoval podruhé.

- **Případ dávky.** NEMPRI a HZUPN se přiřadí k případu dávky
  v `Mzdy → Podání a hlášení` (**Mimořádná podání ▾ → Dávky nemocenského**)
  podle čísla rozhodnutí, jinak podle dne vzniku nebo konce neschopnosti, nebo
  ho import založí jako převzatý. NEMPRI nemocenského den vzniku nenese (zná ho ČSSZ z eNeschopenky),
  proto se přiřadí k případu nebo schválené nepřítomnosti v měsíci události;
  bez ní věta zůstane zablokovaná a den vzniku se nedomýšlí. Zapište nejdřív
  neschopnost v nepřítomnostech a import zopakujte.
- **Co se zapíše.** U NEMPRI stav **vyřízeno předchozím programem**, u HZUPN
  i návrat do práce, hodiny posledního dne, dny práce a poslední den
  neschopnosti. Případ vedený v MyÚčtu jako vlastní podání předchozí program
  nevyřídí; jeho přijetí zapíšete dnem doručení z protokolu ČSSZ. Den doručení
  je u NEMPRI a HZUPN nepovinný.
- **Oprávnění.** Náhled těchto vět uvidí každý, kdo smí import otevřít. Zápis
  NEMPRI, HZUPN a OZUSPOJ vyžaduje i právo ke správě mzdových podání; bez něj se
  věty přeskočí s upozorněním a ostatní věty dávky se zapíší.
- **Poslední den neschopnosti** se odvodí jako den před návratem do práce.
  Při návratu v pondělí import počítá s víkendem: páteční konec vedený
  v evidenci nepřepisuje ani nehlásí jako rozpor. Konec se přepíše, jen když
  leží mimo tuto dobu.
- **Hlídač termínů.** Rozběhnutá neschopnost s převzatým jen NEMPRI zůstává
  v hlídači lhůt, protože HZUPN se podává až po skončení neschopnosti. Po
  převzetí HZUPN a při vyřízeném NEMPRI případ z hlídače zmizí.
- **Osoba a zaměstnavatel.** Osoba podle rodného čísla (u cizince EČP), u HZUPN
  bez rodného čísla podle jména a data narození, vztah podle doby události
  a dne nástupu. Víc shod větu zablokuje. Variabilní symbol a IČ zaměstnavatele
  se porovnávají s vaší firmou; podání jiného zaměstnavatele se nepřevezme.
- **OZUSPOJ.** Soubor nenese den doručení oznámení; ten je v protokolu ČSSZ
  a nárok na slevu na něm stojí. Zadejte ho u věty, bez něj se věta nepoužije.
  Záměr se založí jako převzatý, bez povinnosti oznámení; storno záměru se
  nepřebírá.
- **Opakovaný import.** Případ si pamatuje soubor a větu, ze které vznikl, takže
  opakovaný import téhož souboru nic nezapíše podruhé.

### 90.14.11 Import docházky

Záložka **Docházka** převezme měsíční podklady z docházkového systému (např.
GIRITON) nebo z tabulek z něj složených: hlavní sešit se seznamem, provozní
sešity s hodinami a CSV s osobními čísly, ve formátu XLSX (všechny listy)
a CSV. Vyžaduje oprávnění `payroll.inputs.write`.

**Období a soubory.** Profil mapování se vybere podle počtu rozpoznaných
sloupců; pod výběrem je vidět, který profil se použil, kolik sloupců rozpoznal
a které nezná. **Upravit mapování** otevře záložku **Mapování sloupců**
s nahranými soubory.

**Osoby.** Osoby ze všech souborů se sloučí podle jména, osobní a rodné číslo se
připojí. Tituly (i s překlepem jako „MqA.") a poznámka v závorce jako „(DPP)"
nebo osobní číslo do jména nepatří, „(ml.)" a „(st.)" ano. Jméno lišící se jen
dalším jménem navíc se spojí a ohlásí. Párování vztahu jde podle uložené vazby,
rodného čísla, kódu vztahu nebo jména; nejasnou osobu import nezakládá.
Chybějící osoby lze založit tlačítkem; měsíční mzda z mzdového výměru se
předvyplní jako pravidelná mzda a nástup z poznámky („nový nástup 15. 6. 2026")
jako den nástupu. Sloupce **Datum narození** a **Zdravotní pojišťovna (kód)**
(třímístný, např. 111) se zapíšou rovnou. Automatické založení při použití se
týká jen osob s osobním nebo rodným číslem; osoba bez čísel bývá jinak zapsané
jméno někoho z evidence. Uvádějí-li podklady ukončení, osoba dostane upozornění;
import vztah neukončuje.

**Souhrn a použití.** Tabulka ukáže hodiny a částky s buňkou původu a pro
kontrolu hrubou a čistou mzdu z mzdového exportu. Chybějící mzdovou složku
profilu import na potvrzení založí jako jednorázovou.

**Kontrola období a dvojích vstupů.** Výchozí období je pracovní měsíc mezd.
Import pozná měsíc z názvů souborů a listů („podklady 11-2025", „Mzdy 11-25",
„1125.xlsx", „dochazka-2025-11") a při rozdílu upozorní. Souhrn ukáže i to, že
období má vstupy z jiného importu (např. převod z POHODY nebo PAMICA). V obou
případech je nutné nález výslovně potvrdit. Opakované použití týchž souborů se
za jiný import nepovažuje.

**Měsíční mzda z podkladů.** Tabulka **Měsíční mzda z podkladů** ukáže původní
mzdu, mzdu z podkladů a způsob zápisu. Chybějící mzda se doplní do platné
verze, jiná založí novou verzi od prvního dne období. **Převzít měsíční mzdu
z podkladů** je zapnutá, jen když je co převzít. Mzda se nepřevezme u mzdy podle
docházky (hodinová nebo úkolová složka), u ukončeného nebo nenastoupeného
vztahu, při novější verzi podmínek po začátku období a tam, kde by zasáhla
schválený, zaúčtovaný nebo vyplacený běh. Důvod je vidět u osoby v tabulce;
takové osobě upravte mzdu na kartě vztahu. Výsledek vypíše převzaté
a nepřevzaté mzdy a běhy k přepočtu s odkazem na ně; běh vezme novou mzdu až
z nového snímku vstupů (viz [Mzdové běhy](80_Mzdove_behy.md)).

**Srážky ze mzdy.** Sloupce **Obědy – srážka ze mzdy** a **Srážka ze mzdy** (ve
vzoru GIRITON dotovaná cena obědů a srážky z hlavního seznamu) se k hrubé mzdě
nepřičítají. **Založit srážky ze mzdy** z nich založí dohody o srážce jen pro
importovaný měsíc, strhávané z čisté mzdy. Dohoda patří zaměstnanci a srážky
z víc vztahů se sečtou. Opakovaný import dohodu opraví, dokud se nepoužila ve
schválené mzdě; potom ji nemění a jen to ohlásí. Předpokládá se uzavřená dohoda
o srážkách se zaměstnancem (viz [Dohody o srážkách](87_Dohody_o_srazkach.md)).

**Verze vzoru.** Vzorový profil GIRITON dostane každá firma a s novou verzí
aplikace se aktualizuje sám, pokud jste ho neupravili. Upravený vzor se
nepřepíše: nese v **Mapování sloupců** štítek **Nová verze vzoru** a náhled
upozorní odkazem **Otevřít mapování**. **Aktualizovat vzor** nahradí pravidla
a složky novou verzí a profil uloží; vlastní úpravy se ztratí, proto si profil
případně duplikujte. Pravidla nové verze posílá server s náhledem, tlačítko je
proto aktivní po načtení náhledu docházky nebo zkoušce profilu na souborech.
Smazaný vzor vrátí **Obnovit vzor GIRITON** nad seznamem profilů; vznikne
v aktuální verzi a dál se aktualizuje sám.

Hodnoty se nesčítají napříč listy; při stejném údaji ze dvou listů platí vyšší
priorita a rozdíl je konflikt. Opakovaná hlavička v listu: platí první sloupec.
Vzorce se nepřepočítávají, bere se uložená hodnota. Prázdná buňka ani chyba
vzorce nejsou nula. Trvání nad 24 hodin se převádí správně. Náhled pro stovky
zaměstnanců trvá jednotky sekund.

**Pravidlo s podmínkou** platí jen pro řádky s danou hodnotou jiného sloupce
téhož listu, např. *Odměny → úkolová mzda, když oddělení = výroba*; ostatní
řádky dostanou další pravidlo pro tentýž sloupec. Vzor GIRITON tak čte
odměny ve výrobě jako úkolovou mzdu, jinde jako odměnu, a „Suma hodinovky NOC"
jako příplatek za noční práci.

**Zdanitelná část stravování.** Sloupec s hodnotou jídla nad osvobozený limit
(v GIRITON „Součet z Výpočet pro socku") jde na složku **Zdanitelná část
stravování**: nepeněžní příjem, který zvyšuje hrubou mzdu i oba vyměřovací
základy, ale nevyplácí se. Srážka za obědy jde z čisté mzdy samostatně. V JMHZ má
složka zařazení **10328 úhrn zúčtované mzdy**, protože přesnější uzel číselník
nenabízí; bez zařazení by hlášení nešlo zmrazit.

Použitím vznikne dávka s měsíčním souhrnem hodin po vztazích a původem hodnot.
Peněžní částky se volitelně založí jako **návrhy mzdových vstupů** (viz
[Mzdové složky a vstupy](91_Mzdove_slozky_a_vstupy.md)). Opakovaný import vrátí
existující dávku. Opravený soubor nevytvoří druhou odměnu, protože vstup nese
stálý identifikátor osoby, měsíce a složky; původní návrh nejdřív smažte.

**Porovnání s výpočtem mezd.** Nese-li dávka hrubou a čistou mzdu z mzdového
exportu (např. CSV z předchozího programu), tlačítko v historii importů ji po
výpočtu běhu téhož období porovná s výsledkem po osobách. Rozdíl do 1 Kč je
zaokrouhlení; osoby s rozdílem a bez výpočtu jsou nahoře. U osoby s víc vztahy
se porovná jen hrubá mzda, protože čistou mzdu výpočet vede za osobu.

Import hodin nevytváří záznamy docházky ani absence s konkrétními dny. Se
souhrnem lze zapnout výpočet náhrad mzdy z hodin dovolené, lékaře a překážek
na straně zaměstnavatele podle schváleného průměru (dovolená a lékař 100 %,
překážka podle sazby v pravidle profilu, bez zadání 80 %; nižší sazba, nejméně
60 %, jen při nepříznivém počasí podle § 207 písm. b) nebo částečné
nezaměstnanosti podle § 209 zákoníku práce) a měsíční mzda v rychlém vstupu se
o hodiny nepřítomnosti zkrátí. Bez schváleného průměru se náhrada nezaloží.
Náhradu při nemoci z měsíčního součtu spočítat nejde (rozhoduje prvních 14 dnů
a konkrétní dny); nemoc zadejte v [Absence a dovolená](76_Absence_a_dovolena.md).

### 90.14.12 OIČ z POHODY

Záložka doplní OIČ (IK MPSV) osobám, které už evidujete, z exportu **Tabulka
agendy Personalistika** z POHODY (XLSX, nebo CSV se sloupci Příjmení, Jméno,
Rodné číslo, Osobní číslo a OIC). Hlavičku aplikace najde sama. Liší-li se IČ
v exportu od IČ firmy, náhled upozorní. Vyžaduje oprávnění `payroll.person.write`
a `payroll.employment.write`.

Osoba se hledá podle rodného čísla (zobrazeného maskovaně) a OIČ musí mít
10 číslic se správnou kontrolní číslicí. Stavy řádků:

- **Připraveno**: OIČ se zapíše k dnes platnému vztahu, jinak k poslednímu,
  s platností ode dne nástupu,
- **Už uloženo**: osoba má stejné OIČ,
- **Jiné OIČ v evidenci**: uložené číslo import nikdy nepřepíše; opravu udělejte
  na kartě vztahu,
- **OIČ má jiná osoba**, osoba nenalezena nebo nejednoznačná, osoba bez vztahu,
  duplicitní řádek a neplatné rodné číslo či OIČ: řádek nejde vybrat, důvod je
  u něj.

Řádky bez OIČ se přeskočí. POHODA není protokol ČSSZ, proto čísla před zápisem
ověřte. OIČ se uloží jako ověřený ruční opis ke zvolenému prostředí, stejně jako
na kartě vztahu. Opakovaný import nic nezapíše podruhé.

### 90.14.13 Bezpečnost a časté chyby

Každou hodnotu ověřte proti oficiálnímu zdroji a správné firmě. Úspěšné
uložení nepotvrzuje, že identifikátor nebo účet uznala instituce. Rozpracované
nastavení lze uložit, navazující krok ale může zůstat blokovaný. Privátní klíče,
hesla a SMS kódy nepatří do poznámek ani příloh. Pro podání ČSSZ držte testovací
a produkční nastavení oddělené: testovací certifikát a testovací VS jen pro
test, produkční konfiguraci ověřte samostatně; certifikát patří jen do určeného
bezpečného úložiště.

Časté chyby:

- identifikátor nebo účet zkopírovaný z jiné firmy,
- záměna testovacího a produkčního prostředí,
- chybějící předkontace, která zablokuje účetní krok,
- domněnka, že nastavení datové schránky samo odesílá podání nebo načítá
  doručené zprávy.

## 90.15 Související kapitoly

- [Úplné mzdy](75_Uplne_mzdy.md): co nastavit před první mzdou
- [Zaměstnanci](86_Zamestnanci.md): osoby a pracovní vztahy
- [Mzdové složky a vstupy](91_Mzdove_slozky_a_vstupy.md)
- [Shoda účtování mezd](81_Shoda_uctovani_mezd.md)
- [Podání a hlášení](85_Podani_a_hlaseni.md): zmocnění, certifikát a odeslání
- [Datová schránka](97_Datova_schranka.md)
- [Dimenze](114_Dimenze.md)
