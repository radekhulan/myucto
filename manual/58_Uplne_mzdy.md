# Úplné mzdy — jak začít a jak postupovat

Mzdový modul vede celý pracovní tok jedné účetní: od nastavení zaměstnavatele a
zaměstnanců přes měsíční vstupy, výpočet a kontrolu až po výplatní dokumenty,
platby, zaúčtování a zákonná podání. Jednotlivé kroky jsou oddělené záměrně.
Samotný výpočet mzdy například neodešle peníze, nezaúčtuje doklad a nepodá
hlášení bez další vědomé akce uživatele.

Tato kapitola je praktický rozcestník. Pokud mzdy nastavujete poprvé, projděte
část [Co nastavit před první mzdou](#582-co-nastavit-pred-prvni-mzdou). Při běžném
zpracování pokračujte podle části
[Doporučený měsíční postup](#583-doporuceny-mesicni-postup). Podrobné návody jsou
odkazované přímo u jednotlivých kroků.

## 58.1 Jak je mzdový modul uspořádaný

Základem je **osoba**, která může mít jeden nebo více **pracovních vztahů**.
Pracovní vztah nese smluvní a zákonné podmínky platné v čase. Každý měsíc se k
němu doplní docházka, absence, cestovní náhrady a mzdové složky. Z těchto
podkladů vznikne **revize mzdového běhu**.

Schválená revize je neměnný otisk toho, co bylo skutečně spočítáno. Pozdější
změna živé karty zaměstnance, účtu nebo firemního nastavení ji zpětně
nepřepíše. Oprava vytvoří novou navazující revizi a peněžní či účetní rozdíly
se řeší proti předchozímu schválenému stavu.

Úplné mzdy používají stejný seznam osob jako Mzdová rekapitulace; nezakládají
druhou kopii zaměstnance. Jeden měsíc však nelze uzavřít oběma cestami.

## 58.2 Co nastavit před první mzdou

Nastavení proveďte v tomto pořadí. Údaje z předchozího kroku se používají v
následujících obrazovkách, takže přeskočení obvykle skončí až blokací při
výpočtu nebo podání.

Na přehledu mezd vás tímto pořadím provede **Průvodce prvním nastavením mezd**
(nadpis **Rozjezd mezd krok za krokem**). Má jedenáct kroků ve třech skupinách:

1. **Nastavení zaměstnavatele** — údaje, bez kterých nejde spočítat ani odvést
   mzdu: zaměstnavatel a mzdové účtárny, registrace u ČSSZ a pojišťoven,
   platební účty institucí, předkontace mezd, mzdová politika a připravenost
   a odesílání podání datovou schránkou.
2. **Lidé** — první zaměstnanec, jeho pracovní vztah a odměna, zákonná evidence
   a mzdové složky.
3. **První mzdový měsíc** — jediný krok, kterým průvodce předá štafetu běžnému
   měsíčnímu zpracování.

Každý krok vede přímo na obrazovku, kde se údaj vyplňuje, a dá se odškrtnout.
Kroky, na které nemáte oprávnění, se nenabízejí; prázdná skupina se skryje.
Odškrtnuté kroky a případné skrytí průvodce se ukládají k vašemu uživatelskému
účtu, takže vám zůstanou i na jiném počítači nebo v jiném prohlížeči.

Průvodce zmizí sám, jakmile má firma první vypořádaný mzdový běh. Firma, která
mzdy dávno zpracovává, ho tedy neuvidí vůbec.

**Nezaměňujte ho s průvodcem měsíčním tokem.** Průvodce **Jak to funguje**
zůstává beze změny a popisuje **opakovaný měsíční postup**; průvodce prvním
nastavením řeší **jednorázové rozjetí modulu** a stojí nad ním.

### 58.2.1 1. Aktivujte mzdy a určete první období

V **Firma → Nastavení** zapněte **Vést mzdy** a zvolte první měsíc, od kterého
bude firma používat úplný mzdový modul. Starší měsíce mohou zůstat v
[Mzdové rekapitulaci](57_Mzdy.md). Rozpracovanou aktivaci lze zrušit, dokud je
jen ve stavu nastavení; aktivní začátek už běžný přepínač nezruší, aby
nezmizely vazby na běhy, platby, dokumenty a podání.

Účetní přidělte potřebná oprávnění. Jedna účetní může celý běžný tok připravit,
zkontrolovat, schválit i odeslat; modul nevyžaduje druhého schvalovatele.
Citlivé oblasti, například exekuce nebo nevratný výmaz osobních údajů, mají
samostatná práva uvedená níže.

### 58.2.2 2. Doplňte zaměstnavatele a registrace

V [Nastavení mezd](73_Nastaveni_mezd.md) zkontrolujte zejména:

- identifikační a kontaktní údaje zaměstnavatele;
- výplatní den, běžné pracovní režimy a mzdový kalendář;
- registrace a identifikátory pro ČSSZ, zdravotní pojišťovny a daňovou správu;
- bankovní účty pro výplaty, odvody, srážky a ostatní příjemce;
- předkontace a účty potřebné pro [zaúčtování mezd](64_Shoda_uctovani_mezd.md);
- testovací nebo produkční prostředí a příslušné certifikáty pro podporované
  elektronické kanály.

Úvodní nuly registračních a sériových identifikátorů neopravujte ručně podle
toho, jak vypadají v certifikátu. Použijte přesně hodnotu přidělenou institucí;
aplikace při porovnání zohlední povolený zápis.

### 58.2.3 3. Nastavte datovou schránku firmy

Datová schránka patří ke konkrétní **firmě**, nikoli obecně k instalaci. V
**Firma → Datová schránka** zvolte schránku a prostředí, které se mají pro
firmu používat. Podle konkrétní akce lze použít Mobilní klíč eGovernmentu,
jméno a heslo, jméno, heslo a SMS kód nebo uložený firemní certifikát.
Zapamatované jméno a komunikační kód Mobilního klíče se vážou na kombinaci
firma + přihlášený uživatel + prostředí; nejde o společné firemní heslo.

Datovou schránkou z mezd chodí **přehledy a hlášení zdravotním pojišťovnám**
(HOZ, PPZ), **měsíční hlášení zaměstnavatele ČSSZ (JMHZ)** jako alternativa
k přímému kanálu VREP a **součinnost exekutorům**. Daňová podání — přiznání
k DPH, kontrolní a souhrnné hlášení, přiznání k dani z příjmů — datovkou
z aplikace **nechodí**; ta jdou přes EPO. Poslat je datovkou lze, ale takové
podání nedostane potvrzení s podacím číslem, jen dodejku.

Inbox se nikdy nevybírá automaticky. Nové zprávy se načtou až po otevření
**Příchozích zpráv**, volbě přihlášení, potvrzení právního významu vyzvednutí a
stisknutí akce uživatelem. Odesílací brána zprávy číst neumí vůbec: dokáže jen
vložit koncept, který uživatel po přihlášení v ISDS odešle. **Doručenku proto
stáhněte v datové schránce a nahrajte ji k podání ručně.** Stejně tak se žádné
podání neodešle jen tím, že bylo vytvořeno XML nebo vloženo do odchozí fronty.
Podrobný postup je v [Podáních a hlášeních](68_Podani_a_hlaseni.md).

### 58.2.4 4. Zkontrolujte legislativní sady

V [Legislativních pravidlech mezd](75_Legislativni_pravidla_mezd.md) ověřte,
že je pro zpracovávaný měsíc dostupná přesně účinná sada. Aplikace chybějící
pravidlo nenahradí hodnotou z jiného roku ani odhadem. Přehled schopností
rozlišuje **Podporováno**, **Ruční kontrola** a **Nepodporováno**.

### 58.2.5 5. Založte zaměstnance a pracovní vztahy

Na kartě [Zaměstnanci](69_Zamestnanci.md) nejprve doplňte osobní,
identifikační, daňové, pojistné a platební údaje. Potom založte každý pracovní
vztah a jeho časově účinné podmínky. U údajů, které se běžně nemění — například
druh činnosti, pojištění, daňové prohlášení nebo běžný profil právních
skutečností JMHZ — nastavte výchozí stav na vztahu. V měsíci pak řešte pouze
skutečné změny a výjimky.

Při převodu z jiného programu doplňte také počáteční roční součty, zůstatky
dovolené a další návazné hodnoty. Bez nich může být samostatný měsíční výpočet
správný, ale roční limit, maximální vyměřovací základ nebo roční zúčtování ne.

### 58.2.6 6. Připravte mzdové složky a opakované vstupy

V [Mzdových složkách a vstupech](74_Mzdove_slozky_a_vstupy.md) zkontrolujte
zařazení do daně, sociálního a zdravotního pojištění, JMHZ a zaúčtování.
Pravidelnou mzdu, paušál nebo opakovanou srážku nastavte jako účinný opakovaný
vstup. Jednorázové odměny a výjimky patří do konkrétního měsíce. Odkaz na zdroj
je dobrovolný; nenahrazuje skutečný zákonný údaj a jeho absence sama o sobě
nesmí bránit práci.

### 58.2.7 7. Dokončete nastavení firmy

Po vyplnění zaměstnavatele, účtáren, účtů, předkontací a zaměstnanců můžete
začít zpracovávat první skutečný mzdový měsíc. Aplikace nevyžaduje dva měsíce
paralelního provozu, uměle založený opravný běh, zkoušku obnovy ani kvalifikační
protokol zákazníka.

Testovací podání můžete použít dobrovolně k ověření vlastního certifikátu a
identifikátorů. Testovací a produkční prostředí mají oddělené podání,
certifikáty i stav.

Dokud je na přehledu upozornění **Mzdy jsou zatím v testovacím provozu**, jsou
ostrá podání a mzdové platební příkazy globálně zablokované interní release
branou MyÚčta. Na straně firmy není potřeba nic dokládat ani odblokovávat. Po
interním ověření produktu bude brána uvolněna aktualizací aplikace; běžné
kontroly úplnosti nastavení a jednotlivých podání zůstanou zachované.

## 58.3 Doporučený měsíční postup

Celý mzdový měsíc má **tři fáze**: nejdřív se zapíšou vstupy, pak se z nich
udělá mzdový běh, a nakonec se z hotového běhu platí, účtuje, tisknou doklady a
podává. Následující tabulka je rychlý přehled; podrobný klikací postup je hned
za ní v [§ 58.3.1](#5831-krok-za-krokem-co-presne-klikat) a hlídá skutečné
pořadí tlačítek v aplikaci.

| Pořadí | Co účetní udělá | Kde pokračovat |
|---:|---|---|
| 1 | Otevře správnou firmu a měsíc, zkontroluje nástupy, výstupy a změny podmínek. | [Zaměstnanci](69_Zamestnanci.md) |
| 2 | Doplní absence, dovolenou, docházku a pracovní cesty a **schválí měsíc docházky**. | [Absence a dovolená](59_Absence_a_dovolena.md), [Docházka a směny](60_Dochazka_a_smeny.md), [Cestovní náhrady](61_Cestovni_nahrady.md) |
| 3 | Zadá mzdy, odměny, náhrady a srážky; pro víc lidí použije hromadný vstup. | [Rychlý měsíční vstup](62_Rychly_mesicni_vstup.md), [Mzdové složky a vstupy](74_Mzdove_slozky_a_vstupy.md) |
| 4 | Založí mzdový běh, uzamkne vstupy a spočítá mzdy; projde blokace i varování. | [Mzdové běhy](63_Mzdove_behy.md) |
| 5 | Zkontroluje čisté mzdy, odvody, daně, rozpad po zaměstnancích, srážky a exekuce. | [Mzdové běhy](63_Mzdove_behy.md), [Srážky a exekuce](71_Srazky_a_exekuce.md) |
| 6 | Schválí revizi. Při chybě opraví zdrojový údaj a vytvoří novou revizi; schválený otisk nepřepisuje. | [Mzdové běhy](63_Mzdove_behy.md) |
| 7 | Zaúčtuje mzdy a porovná mzdovou revizi, deník a platby. | [Shoda účtování mezd](64_Shoda_uctovani_mezd.md) |
| 8 | Připraví závazky, vytvoří mzdové příkazy a po výpisu spáruje skutečné úhrady. | [Mzdové příkazy a úhrady](65_Platby_a_uhrady.md) |
| 9 | Vygeneruje výplatní pásky a měsíční balíček a zkontroluje stav po osobách. | [Dokumenty a výstupy](66_Dokumenty_a_vystupy.md) |
| 10 | Připraví JMHZ a hlášení zdravotním pojišťovnám, zkontroluje XML/PDF, zvolí kanál a každé odeslání výslovně potvrdí; nakonec měsíc uzavře. | [Podání a hlášení](68_Podani_a_hlaseni.md) |

### 58.3.1 Krok za krokem: co přesně klikat

Modul najdete v levém menu v sekci **Mzdy** (stojí za Nástroji a před Firmou;
zobrazí se jen firmě, která má mzdy zapnuté). Položky menu jsou úmyslně
seřazené v pořadí měsíčního kroku, takže se dá jít shora dolů:

> **Přehled mezd → Absence a dovolená → Docházka a směny → Cestovní náhrady →
> Rychlý měsíční vstup → Mzdové běhy → Shoda účtování mezd → Mzdové příkazy a
> úhrady → Dokumenty a výstupy → Roční zúčtování → Podání a hlášení**

Pod oddělovačem následuje kmenová evidence (**Zaměstnanci**, Dohody o srážkách,
Srážky a exekuce, Součinnost exekutorům, Oddlužení, Koše benefitů) a pod druhým
oddělovačem jednorázové nastavení (**Nastavení mezd**, Mzdové složky a vstupy,
Legislativní pravidla mezd, Retenční lhůty, Výmaz osobních údajů).

Na **Mzdy → Přehled mezd** je navíc rozcestník **Jak to funguje**, který vede
stejným sledem devíti kroků a odkazuje rovnou na příslušné obrazovky.

#### A. Vstupy — než se vůbec založí běh

1. **Mzdy → Zaměstnanci.** Zkontrolujte nástupy, výstupy a změny podmínek za
   zpracovávaný měsíc. Nového člověka lze založit i z tlačítka **+** v hlavičce
   volbou **Nový zaměstnanec**.
2. **Mzdy → Absence a dovolená.** Zapište dovolenou, nemoc a ostatní překážky
   v práci.
3. **Mzdy → Docházka a směny.** Doplňte odpracovanou dobu a přesčasy a měsíc
   **schvalte** tlačítkem **Schválit měsíc**.

   > [!WARNING]
   > **Příplatky za noc, víkend, svátek a ztížené prostředí vznikají výhradně
   > schválením docházky.** Po uzamčení vstupů se do běhu už nedostanou. Docházku
   > proto schvalte dřív, než v dalším kroku spustíte výpočet.

4. **Mzdy → Cestovní náhrady.** Zapište pracovní cesty a vyúčtování.
5. **Mzdy → Rychlý měsíční vstup.** Zadejte hrubé mzdy, odměny a jednorázové
   položky za měsíc. Máte-li právo mzdové vstupy schvalovat, ukládají se řádky
   rovnou jako **schválené**; bez toho práva vznikají koncepty.
6. **Mzdy → Mzdové složky a vstupy** použijte pro opakující se složky, benefity
   a jednotlivé výjimky. Vstupy zadané tady vznikají **vždy jako koncept** —
   schválit je jde hromadně tlačítkem **Schválit vše**, a to i později přímo
   u blokace v kartě běhu.

#### B. Mzdový běh — **Mzdy → Mzdové běhy**

7. Nahoře vyplňte **Mzdové období** (měsíc) a **Datum výplaty**. Obojí je
   povinné; datum výplaty je kotva, ze které se odvozují splatnosti odvodů
   i termíny podání, a nesmí být později než poslední den měsíce následujícího
   po měsíci, za který mzda přísluší (§ 141 odst. 1 zákoníku práce).
8. Klikněte na **Nový mzdový běh**. Nejde-li to, aplikace pod tlačítkem napíše
   proč — chybí období, chybí datum výplaty, nebo za měsíc už běh existuje.
9. Nad seznamem se ukáže panel **Příprava vstupů za {měsíc}** s odkazy na
   Měsíční zadání mezd, Docházku, Nepřítomnosti, Mzdové složky a Lidi, plus
   přepínač **Zobrazit přehled odvodů**. Projděte jej, dokud je běh koncept —
   potom už se vstupy měnit nedají.
10. Na kartě běhu klikněte na primární (zvýrazněné) tlačítko **Spočítat mzdy**.
    Zamkne vstupy a rovnou spočítá mzdy. Nejdřív se objeví **Kontrola před
    zahájením**; každý nález má vyznačený dopad — **Bez tohohle se nedá počítat** /
    **Pozdější oprava znamená opravnou revizi** / **Doplní se kdykoli, běh to
    nezdrží** — a dialog **Opravdu zahájit mzdový běh?** nabídne
    **Zkontrolovat znovu** nebo **Přesto zahájit**.

    > [!IMPORTANT]
    > Uzamčení vytvoří **neměnný snímek** zaměstnanců, vztahů, složek, data
    > výplaty a podkladů srážek. Co zapíšete potom, se do výpočtu ani do hlášení
    > nedostane, dokud běh znovu neotevřete novou revizí.

11. Projděte sekci **Kontroly běhu** na kartě. Barva říká závažnost:
    **červená = blokace** (bez opravy se dál nedá), **oranžová = varování**
    (chce vaše rozhodnutí), šedá = informace. U nálezu s odkazem
    **Otevřít místo k opravě** se prokliknete rovnou tam, kde se údaj opravuje.
    - Zbyly-li neschválené mzdové vstupy, je přímo u blokace tlačítko
      **Schválit vše** — nemusíte kvůli tomu odcházet na jinou obrazovku.
      Schvaluje se najednou až 500 vstupů a už schválený se přeskočí, takže je
      bezpečné ho použít znovu.
    - U varování, které nejde odstranit, je tlačítko **Schválit výjimku**.
      Vyžaduje odůvodnění celou větou (nejméně 20 znaků a tři slova) a zapíše se
      do auditní stopy. Bez převzetí odpovědnosti běh schválit nejde.
12. Zkontrolujte výsledek. Na kartě jsou tři dlaždice (**Peněžní příjem před
    srážkou**, **Exekuční srážka a paušál**, **K výplatě po srážce**) a odkazy
    **Zobrazit rozpad podle zaměstnanců** (čísla po osobách, **Rozklad čisté
    mzdy**, **Rozklad sociálního a zdravotního pojištění**, **Rozpad daně ze
    závislé činnosti**) a **Zobrazit historii a změny**.
13. Musíte-li něco opravit, opravte **zdrojový údaj** a klikněte
    **Přepočítat**. Přepočet pracuje pořád se stejným zmrazeným snímkem, takže
    ho lze opakovat kolikrát chcete; jde-li o změnu samotného vstupu, otevřete
    novou revizi. Vypočtený výsledek se nikdy neupravuje ručně.
14. Klikněte **Schválit** (zelené tlačítko). Schválení uloží výsledek jako
    závazný, **samo založí výplatní pásky** každé zpracované osoby, v podvojném
    účetnictví připraví rozdílový mzdový deník a zapíše kontrolu i schválení do
    historie běhu. Samostatné tlačítko **Zkontrolovat** aplikace nenabízí —
    kontrola je součástí schválení.

#### C. Po schválení — pořadí je dané a nedá se přeskočit

Primární tlačítko na kartě běhu se po každém kroku samo přepne na ten další:

| Stav běhu | Zvýrazněné tlačítko | Co se stane |
|---|---|---|
| Koncept | **Spočítat mzdy** | zamkne vstupy a spočítá |
| Vstupy uzamčeny / Oprava otevřena | **Přepočítat** | přepočítá ze zmrazeného snímku |
| Spočítáno / Zkontrolováno | **Schválit** | závazný výsledek + výplatní pásky |
| Schváleno | **Zaúčtovat** | zápis do deníku (u daňové evidence se přeskočí) |
| Zaúčtováno | **Připravit platby** | vznikne seznam platebních závazků |
| Platby připraveny / Uhrazeno | **Uzavřít** | uzavře měsíc |
| Čeká na opravu / Zrušeno | **Otevřít opravu** | nová revize nad opravenými vstupy |

Vpravo jsou vedle toho méně časté akce (**Vyžádat opravu**, **Zrušit běh** —
červeně úplně vpravo). Tlačítko **Označit za uhrazené** neexistuje: úhrada není
rozhodnutí účetní, ale fakt — do stavu **Uhrazeno** běh překlopí server sám,
jakmile poslední závazek dosedne na spárovanou platbu. Na kartě je proto věta
**Úhrady doložené výpisem: {n} z {m} závazků** s odkazem **Zobrazit platby**.

15. **Zaúčtování.** Klikněte **Zaúčtovat** přímo na kartě běhu. Použijí se
    předkontace **zmrazené při uzamknutí vstupů**, takže pozdější změna
    nastavení už zkontrolovanou revizi nezmění. Je-li účetní období uzamčené,
    datum deníku se posune na první otevřený den. Výsledek zkontrolujte na
    **Mzdy → Shoda účtování mezd** — porovná mzdovou revizi, skutečný deník
    a platební závazky po kategoriích a ukáže, na které straně případný rozdíl
    vznikl.
16. **Platby.** Klikněte **Připravit platby** na kartě běhu a pokračujte na
    **Mzdy → Mzdové příkazy a úhrady**. Stránka má tři záložky:
    - **Co zaplatit** — tlačítkem **Připravit závazky** vzniknou přesné závazky
      bez duplicit (čisté mzdy, sociální a zdravotní pojištění, záloha na daň,
      srážková daň, standardní i exekuční srážky). Opakované spuštění nic
      nezduplikuje.
    - **Mzdové příkazy** — vyberte kompatibilní platby, zkontrolujte součet
      a **Účet plátce** a klikněte **Vytvořit mzdový příkaz** (formát **ABO / KPC
      pro českou banku**, **SEPA XML pro EUR**, nebo **ruční hotovostní výplata**).
    - **Spárování úhrad** — po načtení bankovního výpisu se skutečné úhrady
      spárují se závazky.
17. **Dokumenty.** Otevřete **Mzdy → Dokumenty a výstupy**, záložka **Měsíční
    výstupy**, a spusťte **Dávka dokumentů ({účtárna})**. Generování běží na
    pozadí po osobách s ukazatelem průběhu; po dokončení všech osob vznikne
    **Stáhnout měsíční ZIP**. Neúspěšnou položku lze jednotlivě **Opakovat**.
18. **Podání.** Na **Mzdy → Podání a hlášení** připravte JMHZ a hlášení
    zdravotním pojišťovnám. Záložka **Měsíční přehled pro účetní** ukáže
    u každé povinnosti, co se generuje, kam a jakou cestou to jde, do kdy a
    v jakém je to stavu. Zkontrolujte XML i PDF, zvolte kanál a **každé odeslání
    výslovně potvrďte**. Nic se neodešle jen tím, že vzniklo XML nebo že se
    záznam vložil do odchozí fronty.
19. **Uzavření měsíce.** Až jsou platby doložené a podání odeslaná, klikněte na
    kartě běhu **Uzavřít**. Uzavřený běh jde otevřít už jen přes
    **Vyžádat opravu** a následné **Otevřít opravu** — vznikne nová revize,
    původní zůstane dohledatelná a dřív vydané dokumenty zůstávají platné.

#### D. Co potřebujete za oprávnění

Bez potřebného práva se tlačítko **vůbec nezobrazí** (není zašedlé), takže
chybějící akce je nejčastěji chybějící oprávnění, ne chyba:

| Tlačítko / akce | Oprávnění |
|---|---|
| **Nový mzdový běh**, **Smazat prázdný běh** | `payroll.inputs.write` |
| **Spočítat mzdy**, **Přepočítat**, **Uzamknout vstupy** | `payroll.calculate` |
| **Vyžádat opravu** | `payroll.review` |
| **Schválit**, **Uzavřít**, **Schválit vše**, **Schválit výjimku** | `payroll.approve` |
| **Otevřít opravu**, **Zrušit běh** | `payroll.reopen` |
| **Zaúčtovat** a stránka Shoda účtování mezd | `payroll.post` |
| **Připravit platby** a celá stránka Mzdové příkazy a úhrady | `payroll.payments` |
| **Dávka dokumentů**, mzdový list, potvrzení, archivní ZIPy | `payroll.documents` |
| Podání a hlášení | `payroll.submissions` |
| **Schválit měsíc** v docházce | `payroll.approve` (zápis docházky `payroll.time.write`) |

Úplný seznam práv modulu je v [§ 58.9](#589-opravneni).

### 58.3.2 Na co si dát pozor

**Příplatky za práci v noci, o víkendu, ve svátek a ve ztíženém prostředí
vznikají ze schválené docházky** — jinou cestou je zadat nelze, a docházku je
proto potřeba schválit dřív, než se u běhu uzamknou vstupy. Firma, která
docházku nevede, tyto příplatky z aplikace nedostane a musí je řešit vlastními
vstupy. Příplatky za svátek (§ 115) a za ztížené prostředí (§ 117) zatím nelze
dokončit vůbec, protože chybí obrazovka pro sjednanou zásadu a pro počet
ztěžujících vlivů. Podrobnosti a omezení jsou v
[Docházce a směnách](60_Dochazka_a_smeny.md#6093-zakonne-priplatky-ke-mzde-114-az-118).

Zelený výpočet ještě neznamená dokončený měsíc. Měsíc je prakticky hotový až
tehdy, když souhlasí schválená revize, dokumenty, skutečně provedené platby,
zaúčtování a přijaté protokoly podání. Naopak vytvořený soubor nebo doručenka
ISDS sama neprokazuje, že cílová instituce podání věcně přijala.

## 58.4 Co kontrolovat průběžně

Některé události nečekají na konec měsíce:

- nástup, změnu nebo skončení vztahu zapište k datu účinnosti a splňte
  registrační nebo oznamovací lhůtu;
- nemoc, ošetřování, mateřství, rodičovství a jiné dlouhé absence zapisujte
  průběžně, aby se nepřehlédla navazující povinnost;
- doručenou exekuci, insolvenci nebo dohodu o srážkách evidujte ihned, včetně
  pořadí a ověřených podkladů;
- sledujte expiraci certifikátů, změny registrací, bankovních účtů a datové
  schránky firmy;
- po legislativní aktualizaci zkontrolujte účinnost nové sady před prvním
  výpočtem, který ji má použít;
- inbox datové schránky načítejte vědomě podle interního režimu firmy. Každé
  načtení musí spustit a potvrdit uživatel, protože vyzvednutí může založit
  doručení a právní lhůtu;
- na přehledu mezd sledujte **Provozní přehled mezd**. U fronty dokumentů a
  archivních exportů ukazuje nejen počty čekajících, opakovaných a vadných
  úloh, ale také stáří nejstarší aktivní položky a čas posledního úspěšného
  dokončení. Dlouhé stáří při nulovém pokroku je důvod zkontrolovat plánované
  úlohy; údaj „Zatím nikdy“ po prvním očekávaném běhu znamená, že úspěšné
  dokončení zatím není doložené. Karta **Provozní shoda** souhrnně ukazuje
  otevřené rozdíly, blokátory a období s chybějícím podkladem mezi schválenou
  mzdou, deníkem, platbami, zdravotními přehledy a JMHZ. Nulový počet znamená,
  že poslední uložená kontrola nemá otevřený nález, nikoli náhradu věcné
  kontroly mzdové účetní;
- u větší firmy pracujte s filtry, hledáním a hromadnými měsíčními vstupy.
  Neprocházejte stovky zaměstnanců jen proto, abyste znovu potvrzovali stav,
  který se od minulého období nezměnil.

## 58.5 Roční a mimořádné práce

Na přelomu roku nebo při roční uzávěrce zejména:

1. ověřte, že jsou schválené všechny měsíce a nevznikla neuzavřená opravná
   revize, neprovedená platba nebo nevyřešené podání;
2. porovnejte roční součty daně, sociálního a zdravotního pojištění s měsíčními
   podáními, účetnictvím a bankou;
3. zkontrolujte roční akumulátory, maximální vyměřovací základy, převod
   dovolené a počáteční hodnoty nového roku;
4. zpracujte [roční zúčtování daně](67_Rocni_zuctovani.md) pouze zaměstnancům,
   kteří splňují podmínky a doložili podklady;
5. připravte zákonná potvrzení a evidenční výstupy v rozsahu, který aplikace
   označuje jako podporovaný; u ruční kontroly výsledek před vydáním ověřte;
6. podejte obě roční
   [vyúčtování daně](68_Podani_a_hlaseni.md#6813-vyuctovani-zalohove-a-srazkove-dane),
   zálohové i srážkové; jsou to dvě samostatná podání s různou lhůtou;
7. projděte [retenční lhůty](76_Retencni_lhuty.md), zákonná zadržení a žádosti
   o [výmaz osobních údajů](77_Vymaz_osobnich_udaju.md). Samotný konec roku
   není důvodem ke smazání mzdových podkladů.

Stejný kontrolní postup použijte při převodu mezd z jiného systému, změně
účetní, reorganizaci mzdových účtáren nebo opravě staršího období.

## 58.6 Podporovaný rozsah a ruční kontrola

Podporovány jsou scénáře, pro které aplikace nabídne potřebné údaje, výpočet a
kontrolu. Neobvyklé souběhy a odvodové režimy, nepokryté registrace,
nepodporované roční odpočty nebo výstupní potvrzení závislé na chybějícím
ověřeném přepočtu zpracujte ručně nebo s mzdovým specialistou. Chybějící
právní skutečnost nenahrazujte podobným polem.

Tabulka **Rozsah modulu** na přehledu uvádí pouze funkce, které jsou v dané
verzi bezpečně dostupné, a proto mají všechny zobrazené řádky zelený stav.
Neznamená to, že aplikace automatizuje libovolný hypotetický scénář. Funkce,
pro které chybí oficiální formát, transport nebo úplné kontroly, zůstávají
fail-closed a v seznamu dostupných funkcí se nezobrazí.

JMHZ podporuje řízené storno celého podání i obsahovou opravu vybraných
formulářů z nové úplné přípravy. Přijatý formulář se opravuje se zachovanou
identitou, odmítnutý nebo chybějící se doplní jako nový. Podrobnosti jsou v
kapitole
[Podání a hlášení](68_Podani_a_hlaseni.md#689-storno-a-obsahova-oprava-jmhz).

## 58.7 Kapitoly

1. [Absence a dovolená](59_Absence_a_dovolena.md)
2. [Docházka a směny](60_Dochazka_a_smeny.md)
3. [Cestovní náhrady](61_Cestovni_nahrady.md)
4. [Rychlý měsíční vstup](62_Rychly_mesicni_vstup.md)
5. [Mzdové běhy](63_Mzdove_behy.md)
6. [Shoda účtování mezd](64_Shoda_uctovani_mezd.md)
7. [Mzdové příkazy a úhrady](65_Platby_a_uhrady.md)
8. [Dokumenty a výstupy](66_Dokumenty_a_vystupy.md)
9. [Roční zúčtování](67_Rocni_zuctovani.md)
10. [Podání a hlášení](68_Podani_a_hlaseni.md)
11. [Zaměstnanci](69_Zamestnanci.md)
12. [Dohody o srážkách](70_Dohody_o_srazkach.md)
13. [Srážky a exekuce](71_Srazky_a_exekuce.md)
14. [Koše benefitů](72_Kose_benefitu.md)
15. [Nastavení mezd](73_Nastaveni_mezd.md)
16. [Mzdové složky a vstupy](74_Mzdove_slozky_a_vstupy.md)
17. [Legislativní pravidla mezd](75_Legislativni_pravidla_mezd.md)
18. [Retenční lhůty](76_Retencni_lhuty.md)
19. [Výmaz osobních údajů](77_Vymaz_osobnich_udaju.md)

## 58.8 Společná bezpečnostní pravidla

- Pracujte jen ve správné firmě, prostředí a mzdovém období.
- Oprávnění přidělujte podle skutečné role; mzdy obsahují citlivé osobní údaje.
- Doklad o odeslání není doklad o věcném přijetí. U podání kontrolujte i doručenku, inbox a stav u instituce.
- ISDS ani inbox aplikace neobsluhuje automaticky. Každé vytvoření konceptu, přihlášení, načtení zpráv a potvrzení doručení musí spustit uživatel.
- Přihlašovací údaje, certifikáty, privátní klíče a SMS kódy nevkládejte do poznámek, příloh ani evidence zdrojů.
- Před uzavřením období uchovejte kontrolní výstupy a porovnejte součty mezd, plateb, zaúčtování a podání.

## 58.9 Oprávnění

Základní čtení mzdového modulu vyžaduje oprávnění `payroll`. Citlivé nebo
nevratné kroky jsou oddělené:

| Oblast | Potřebné oprávnění |
|---|---|
| Nastavení zaměstnavatele | `payroll.settings` |
| Změna osoby a ověření výplatního účtu | `payroll.person.write` |
| Odhalení citlivých osobních údajů | `payroll.person.read_sensitive` |
| Vztahy, podmínky a životní cyklus | `payroll.employment.write` |
| Docházka a absence | `payroll.time.write` |
| Mzdové vstupy (založení běhu, smazání prázdného běhu) | `payroll.inputs.write` |
| Výpočet mezd (Spočítat mzdy, Přepočítat, Uzamknout vstupy) | `payroll.calculate` |
| Kontrola běhu a vyžádání opravy | `payroll.review` |
| Schválení mzdových vstupů, běhu, výjimky a uzavření | `payroll.approve` |
| Znovuotevření schváleného nebo zrušeného běhu | `payroll.reopen` |
| Platby, dávky a párování | `payroll.payments` |
| Dokumenty a měsíční balíček | `payroll.documents` |
| Podání a hlášení | `payroll.submissions` |
| Důkazy zdravotního pojištění | `payroll.health_evidence` |
| Mzdové sestavy a exporty | `payroll.reports` |
| Zaúčtování | `payroll.post` |
| Exekuce a nucené srážky | `payroll.enforcement` |
| Součinnost exekutorům | `payroll.enforcement.cooperation` |
| Insolvenční režim | `payroll.insolvency` |
| Retence a zadržení výmazu | `payroll.retention` |
| Schválení a provedení výmazu | `payroll.erasure` |
| Správa legislativních sad | `payroll.rulesets` |

Samostatná práva nejsou jen organizační pomůcka. Například výchozí účetní role
nemá právo provést nevratný výmaz a běžné mzdové oprávnění samo neotevírá
exekuční spisy. Přístup přidělujte konkrétním rolím, nikoli všem uživatelům
firmy.

## 58.10 Kde začít při potížích

Nejprve zkontrolujte aktivaci a podporovaný rozsah výše, potom [mzdové běhy](63_Mzdove_behy.md) a [legislativní pravidla](75_Legislativni_pravidla_mezd.md). Obecné diagnostické postupy jsou v kapitole [Řešení problémů](999_Reseni_problemu.md).
