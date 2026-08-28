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

| Pořadí | Co účetní udělá | Kde pokračovat |
|---:|---|---|
| 1 | Otevře správnou firmu a měsíc, zkontroluje nástupy, výstupy a změny podmínek. | [Zaměstnanci](69_Zamestnanci.md) |
| 2 | Doplní směny, odpracovanou dobu, absence, dovolenou a pracovní cesty. | [Docházka a směny](60_Dochazka_a_smeny.md), [Absence a dovolená](59_Absence_a_dovolena.md), [Cestovní náhrady](61_Cestovni_nahrady.md) |
| 3 | Zadá odměny, náhrady, srážky a ostatní měsíční změny; pro větší počet lidí použije rychlý hromadný vstup a vyhledávání. | [Rychlý měsíční vstup](62_Rychly_mesicni_vstup.md), [Mzdové složky a vstupy](74_Mzdove_slozky_a_vstupy.md) |
| 4 | Uzamkne vstupy (zbylé koncepty schválí jedním tlačítkem přímo u blokace), spustí výpočet a projde blokace i varování. | [Mzdové běhy](63_Mzdove_behy.md) |
| 5 | Zkontroluje čisté mzdy, odvody, daně, součty podle zaměstnanců a případné srážky nebo exekuce. | [Mzdové běhy](63_Mzdove_behy.md), [Srážky a exekuce](71_Srazky_a_exekuce.md) |
| 6 | Schválí výslednou revizi. Při nalezené chybě opraví zdrojový údaj a vytvoří novou revizi; nepřepisuje schválený otisk. | [Mzdové běhy](63_Mzdove_behy.md) |
| 7 | Vygeneruje výplatní pásky a povinné dokumenty a zkontroluje stav po jednotlivých osobách. | [Dokumenty a výstupy](66_Dokumenty_a_vystupy.md) |
| 8 | Připraví a provede výplaty, odvody a ostatní platby; následně je spáruje s bankou. | [Mzdové příkazy a úhrady](65_Platby_a_uhrady.md) |
| 9 | Připraví JMHZ a hlášení zdravotním pojišťovnám, zkontroluje XML/PDF, zvolí kanál a každé odeslání výslovně potvrdí. | [Podání a hlášení](68_Podani_a_hlaseni.md) |
| 10 | Zaúčtuje mzdy a porovná mzdovou revizi, platby, účetní zápisy a podání. | [Shoda účtování mezd](64_Shoda_uctovani_mezd.md) |

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
6. projděte [retenční lhůty](76_Retencni_lhuty.md), zákonná zadržení a žádosti
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
| Vztahy, podmínky a životní cyklus | `payroll.employment.write` |
| Schválení mzdových vstupů a běhu | `payroll.approve` |
| Platby, dávky a párování | `payroll.payments` |
| Dokumenty a měsíční balíček | `payroll.documents` |
| Podání a hlášení | `payroll.submissions` |
| Zaúčtování | `payroll.post` |
| Exekuce a nucené srážky | `payroll.enforcement` |
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
