# Úplné mzdy — jak začít a jak postupovat

Mzdový modul vede celý pracovní tok jedné účetní: od nastavení zaměstnavatele a
zaměstnanců přes měsíční vstupy, výpočet a kontrolu až po výplatní dokumenty,
platby, zaúčtování a zákonná podání. Jednotlivé kroky jsou oddělené záměrně.
Samotný výpočet mzdy například neodešle peníze, nezaúčtuje doklad a nepodá
hlášení bez další vědomé akce uživatele.

Tato kapitola je praktický rozcestník. Pokud mzdy nastavujete poprvé, projděte
část [Co nastavit před první mzdou](#co-nastavit-pred-prvni-mzdou). Při běžném
zpracování pokračujte podle části
[Doporučený měsíční postup](#doporuceny-mesicni-postup). Podrobné návody jsou
odkazované přímo u jednotlivých kroků.

## Jak je mzdový modul uspořádaný

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

## Co nastavit před první mzdou

Nastavení proveďte v tomto pořadí. Údaje z předchozího kroku se používají v
následujících obrazovkách, takže přeskočení obvykle skončí až blokací při
výpočtu nebo podání.

### 1. Aktivujte mzdy a určete první období

V **Firma → Nastavení** zapněte **Vést mzdy** a zvolte první měsíc, od kterého
bude firma používat úplný mzdový modul. Starší měsíce mohou zůstat v
[Mzdové rekapitulaci](57_Mzdy.md). Rozpracovanou aktivaci lze zrušit, dokud je
jen ve stavu nastavení; aktivní začátek už běžný přepínač nezruší, aby
nezmizely vazby na běhy, platby, dokumenty a podání.

Účetní přidělte potřebná oprávnění. Jedna účetní může celý běžný tok připravit,
zkontrolovat, schválit i odeslat; modul nevyžaduje druhého schvalovatele.
Citlivé oblasti, například exekuce nebo nevratný výmaz osobních údajů, mají
samostatná práva uvedená níže.

### 2. Doplňte zaměstnavatele a registrace

V [Nastavení mezd](58o_Nastaveni_mezd.md) zkontrolujte zejména:

- identifikační a kontaktní údaje zaměstnavatele;
- výplatní den, běžné pracovní režimy a mzdový kalendář;
- registrace a identifikátory pro ČSSZ, zdravotní pojišťovny a daňovou správu;
- bankovní účty pro výplaty, odvody, srážky a ostatní příjemce;
- předkontace a účty potřebné pro [zaúčtování mezd](58f_Shoda_uctovani_mezd.md);
- testovací nebo produkční prostředí a příslušné certifikáty pro podporované
  elektronické kanály.

Úvodní nuly registračních a sériových identifikátorů neopravujte ručně podle
toho, jak vypadají v certifikátu. Použijte přesně hodnotu přidělenou institucí;
aplikace při porovnání zohlední povolený zápis.

### 3. Nastavte datovou schránku firmy

Datová schránka patří ke konkrétní **firmě**, nikoli obecně k instalaci. V
**Firma → Datová schránka** zvolte schránku a prostředí, které se mají pro
firmu používat. Podle konkrétní akce lze použít Mobilní klíč eGovernmentu,
jméno a heslo, jméno, heslo a SMS kód nebo uložený firemní certifikát.
Zapamatované jméno a komunikační kód Mobilního klíče se vážou na kombinaci
firma + přihlášený uživatel + prostředí; nejde o společné firemní heslo.

Inbox se nikdy nevybírá automaticky. Nové zprávy se načtou až po otevření
**Příchozích zpráv**, volbě přihlášení, potvrzení právního významu vyzvednutí a
stisknutí akce uživatelem. Stejně tak se žádné podání neodešle jen tím, že bylo
vytvořeno XML nebo vloženo do odchozí fronty. Podrobný postup je v
[Podáních a hlášeních](58j_Podani_a_hlaseni.md).

### 4. Zkontrolujte legislativní sady

V [Legislativních pravidlech mezd](58q_Legislativni_pravidla_mezd.md) ověřte,
že je pro zpracovávaný měsíc dostupná přesně účinná sada. Aplikace chybějící
pravidlo nenahradí hodnotou z jiného roku ani odhadem. Přehled schopností
rozlišuje **Podporováno**, **Ruční kontrola** a **Nepodporováno**.

### 5. Založte zaměstnance a pracovní vztahy

Na kartě [Zaměstnanci](58k_Zamestnanci.md) nejprve doplňte osobní,
identifikační, daňové, pojistné a platební údaje. Potom založte každý pracovní
vztah a jeho časově účinné podmínky. U údajů, které se běžně nemění — například
druh činnosti, pojištění, daňové prohlášení nebo běžný profil právních
skutečností JMHZ — nastavte výchozí stav na vztahu. V měsíci pak řešte pouze
skutečné změny a výjimky.

Při převodu z jiného programu doplňte také počáteční roční součty, zůstatky
dovolené a další návazné hodnoty. Bez nich může být samostatný měsíční výpočet
správný, ale roční limit, maximální vyměřovací základ nebo roční zúčtování ne.

### 6. Připravte mzdové složky a opakované vstupy

V [Mzdových složkách a vstupech](58p_Mzdove_slozky_a_vstupy.md) zkontrolujte
zařazení do daně, sociálního a zdravotního pojištění, JMHZ a zaúčtování.
Pravidelnou mzdu, paušál nebo opakovanou srážku nastavte jako účinný opakovaný
vstup. Jednorázové odměny a výjimky patří do konkrétního měsíce. Odkaz na zdroj
je dobrovolný; nenahrazuje skutečný zákonný údaj a jeho absence sama o sobě
nesmí bránit práci.

### 7. Projděte celý tok v testovacím prostředí

Před prvním ostrým měsícem vytvořte alespoň jeden úplný test: zaměstnanec,
docházka nebo rychlý měsíční vstup, schválený běh, výplatní dokument, platba,
zaúčtování a testovací hlášení. U elektronického podání ověřte nejen vznik
souboru, ale také protokol a výsledek cílové instituce. Testovací a produkční
prostředí mají oddělené podání, certifikáty i stav.

## Doporučený měsíční postup

| Pořadí | Co účetní udělá | Kde pokračovat |
|---:|---|---|
| 1 | Otevře správnou firmu a měsíc, zkontroluje nástupy, výstupy a změny podmínek. | [Zaměstnanci](58k_Zamestnanci.md) |
| 2 | Doplní směny, odpracovanou dobu, absence, dovolenou a pracovní cesty. | [Docházka a směny](58b_Dochazka_a_smeny.md), [Absence a dovolená](58a_Absence_a_dovolena.md), [Cestovní náhrady](58c_Cestovni_nahrady.md) |
| 3 | Zadá odměny, náhrady, srážky a ostatní měsíční změny; pro větší počet lidí použije rychlý hromadný vstup a vyhledávání. | [Rychlý měsíční vstup](58d_Rychly_mesicni_vstup.md), [Mzdové složky a vstupy](58p_Mzdove_slozky_a_vstupy.md) |
| 4 | Schválí vstupy, spustí výpočet a projde blokace i varování. | [Mzdové běhy](58e_Mzdove_behy.md) |
| 5 | Zkontroluje čisté mzdy, odvody, daně, součty podle zaměstnanců a případné srážky nebo exekuce. | [Mzdové běhy](58e_Mzdove_behy.md), [Srážky a exekuce](58m_Srazky_a_exekuce.md) |
| 6 | Schválí výslednou revizi. Při nalezené chybě opraví zdrojový údaj a vytvoří novou revizi; nepřepisuje schválený otisk. | [Mzdové běhy](58e_Mzdove_behy.md) |
| 7 | Vygeneruje výplatní pásky a povinné dokumenty a zkontroluje stav po jednotlivých osobách. | [Dokumenty a výstupy](58h_Dokumenty_a_vystupy.md) |
| 8 | Připraví a provede výplaty, odvody a ostatní platby; následně je spáruje s bankou. | [Mzdové příkazy a úhrady](58g_Platby_a_uhrady.md) |
| 9 | Připraví JMHZ a hlášení zdravotním pojišťovnám, zkontroluje XML/PDF, zvolí kanál a každé odeslání výslovně potvrdí. | [Podání a hlášení](58j_Podani_a_hlaseni.md) |
| 10 | Zaúčtuje mzdy a porovná mzdovou revizi, platby, účetní zápisy a podání. | [Shoda účtování mezd](58f_Shoda_uctovani_mezd.md) |

Zelený výpočet ještě neznamená dokončený měsíc. Měsíc je prakticky hotový až
tehdy, když souhlasí schválená revize, dokumenty, skutečně provedené platby,
zaúčtování a přijaté protokoly podání. Naopak vytvořený soubor nebo doručenka
ISDS sama neprokazuje, že cílová instituce podání věcně přijala.

## Co kontrolovat průběžně

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
- u větší firmy pracujte s filtry, hledáním a hromadnými měsíčními vstupy.
  Neprocházejte stovky zaměstnanců jen proto, abyste znovu potvrzovali stav,
  který se od minulého období nezměnil.

## Roční a mimořádné práce

Na přelomu roku nebo při roční uzávěrce zejména:

1. ověřte, že jsou schválené všechny měsíce a nevznikla neuzavřená opravná
   revize, neprovedená platba nebo nevyřešené podání;
2. porovnejte roční součty daně, sociálního a zdravotního pojištění s měsíčními
   podáními, účetnictvím a bankou;
3. zkontrolujte roční akumulátory, maximální vyměřovací základy, převod
   dovolené a počáteční hodnoty nového roku;
4. zpracujte [roční zúčtování daně](58i_Rocni_zuctovani.md) pouze zaměstnancům,
   kteří splňují podmínky a doložili podklady;
5. připravte zákonná potvrzení a evidenční výstupy v rozsahu, který aplikace
   označuje jako podporovaný; u ruční kontroly výsledek před vydáním ověřte;
6. projděte [retenční lhůty](58r_Retencni_lhuty.md), zákonná zadržení a žádosti
   o [výmaz osobních údajů](58s_Vymaz_osobnich_udaju.md). Samotný konec roku
   není důvodem ke smazání mzdových podkladů.

Stejný kontrolní postup použijte při převodu mezd z jiného systému, změně
účetní, reorganizaci mzdových účtáren nebo opravě staršího období.

## Podporovaný rozsah a ruční kontrola

Podporovány jsou scénáře, pro které aplikace nabídne potřebné údaje, výpočet a
kontrolu. Neobvyklé souběhy a odvodové režimy, nepokryté registrace,
nepodporované roční odpočty nebo výstupní potvrzení závislé na chybějícím
ověřeném přepočtu zpracujte ručně nebo s mzdovým specialistou. Chybějící
právní skutečnost nenahrazujte podobným polem.

JMHZ podporuje řízené storno celého podání i obsahovou opravu vybraných
formulářů z nové úplné přípravy. Přijatý formulář se opravuje se zachovanou
identitou, odmítnutý nebo chybějící se doplní jako nový. Podrobnosti jsou v
kapitole
[Podání a hlášení](58j_Podani_a_hlaseni.md#storno-a-obsahova-oprava-jmhz).

## Kapitoly

1. [Absence a dovolená](58a_Absence_a_dovolena.md)
2. [Docházka a směny](58b_Dochazka_a_smeny.md)
3. [Cestovní náhrady](58c_Cestovni_nahrady.md)
4. [Rychlý měsíční vstup](58d_Rychly_mesicni_vstup.md)
5. [Mzdové běhy](58e_Mzdove_behy.md)
6. [Shoda účtování mezd](58f_Shoda_uctovani_mezd.md)
7. [Mzdové příkazy a úhrady](58g_Platby_a_uhrady.md)
8. [Dokumenty a výstupy](58h_Dokumenty_a_vystupy.md)
9. [Roční zúčtování](58i_Rocni_zuctovani.md)
10. [Podání a hlášení](58j_Podani_a_hlaseni.md)
11. [Zaměstnanci](58k_Zamestnanci.md)
12. [Dohody o srážkách](58l_Dohody_o_srazkach.md)
13. [Srážky a exekuce](58m_Srazky_a_exekuce.md)
14. [Koše benefitů](58n_Kose_benefitu.md)
15. [Nastavení mezd](58o_Nastaveni_mezd.md)
16. [Mzdové složky a vstupy](58p_Mzdove_slozky_a_vstupy.md)
17. [Legislativní pravidla mezd](58q_Legislativni_pravidla_mezd.md)
18. [Retenční lhůty](58r_Retencni_lhuty.md)
19. [Výmaz osobních údajů](58s_Vymaz_osobnich_udaju.md)

## Společná bezpečnostní pravidla

- Pracujte jen ve správné firmě, prostředí a mzdovém období.
- Oprávnění přidělujte podle skutečné role; mzdy obsahují citlivé osobní údaje.
- Doklad o odeslání není doklad o věcném přijetí. U podání kontrolujte i doručenku, inbox a stav u instituce.
- ISDS ani inbox aplikace neobsluhuje automaticky. Každé vytvoření konceptu, přihlášení, načtení zpráv a potvrzení doručení musí spustit uživatel.
- Přihlašovací údaje, certifikáty, privátní klíče a SMS kódy nevkládejte do poznámek, příloh ani evidence zdrojů.
- Před uzavřením období uchovejte kontrolní výstupy a porovnejte součty mezd, plateb, zaúčtování a podání.

## Oprávnění

Základní čtení mzdového modulu vyžaduje oprávnění `payroll`. Citlivé nebo
nevratné kroky jsou oddělené:

| Oblast | Potřebné oprávnění |
|---|---|
| Nastavení zaměstnavatele | `payroll.settings` |
| Změna osoby a ověření výplatního účtu | `payroll.person.write` |
| Vztahy, podmínky a životní cyklus | `payroll.employment.write` |
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

## Kde začít při potížích

Nejprve zkontrolujte aktivaci a podporovaný rozsah výše, potom [mzdové běhy](58e_Mzdove_behy.md) a [legislativní pravidla](58q_Legislativni_pravidla_mezd.md). Obecné diagnostické postupy jsou v kapitole [Řešení problémů](999_Reseni_problemu.md).
