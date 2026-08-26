# Úplné mzdy — rozcestník

Mzdový modul pokrývá základní tok od nastavení zaměstnavatele a zaměstnance přes vstupy, výpočet, uzavření, platby a zaúčtování až po dokumenty a vybraná elektronická podání. Jde o zkušební agendu: před ostrým použitím vždy ověřte výsledek proti pracovněprávním podkladům, platným pravidlům a potvrzení cílové instituce.

## Aktivace a podporovaný rozsah

Modul zapněte v nastavení firmy a uživatelům přidělte jen oprávnění odpovídající jejich roli. Aktivace pouze zpřístupní stránky; nezaloží nastavení zaměstnavatele, osoby, vztahy ani běhy.

Podporovány jsou běžné scénáře, pro které aplikace nabídne potřebné údaje, výpočet a kontrolu. Neobvyklé souběhy a odvodové režimy, nepokryté registrace a opravy jiných podání než podporované JMHZ, nepodporované roční odpočty a výstupní potvrzení závislé na chybějícím ověřeném přepočtu zpracujte ručně nebo s mzdovým specialistou. Chybějící právní skutečnost nenahrazujte podobným polem. Odkaz na podklad bývá volitelným důkazem; zákonné identifikátory, data a částky jsou skutečné vstupy.

Přepínač **Vést mzdy** je v **Firma → Nastavení** a ve výchozím stavu je
vypnutý. Po zapnutí zvolte na přehledu první měsíc, od kterého firma použije
úplný mzdový modul. Starší měsíce mohou zůstat v
[Mzdové rekapitulaci](57_Mzdy.md), jeden měsíc však nelze uzavřít oběma
cestami. Rozpracovanou aktivaci lze zrušit, dokud je jen ve stavu nastavení;
aktivní začátek už obyčejný přepínač nezruší, aby nezmizely vazby na běhy,
platby, dokumenty a podání.

Přehled schopností rozlišuje **Podporováno**, **Ruční kontrola** a
**Nepodporováno**. Chybějící pravidlo se nikdy nenahrazuje hodnotou z jiného
roku ani odhadem. JMHZ podporuje řízené storno celého podání a storno vybraných
vztahů; úplnou opravu hodnot nelze zaměňovat za zneplatnění součásti.
Podrobnosti jsou v kapitole
[Podání a hlášení](58j_Podani_a_hlaseni.md#storno-a-nasledna-oprava-jmhz).

## Doporučený pracovní tok

Nejprve nastavte zaměstnavatele, zaměstnance, vztahy, složky a kalendář. Potom zadejte docházku, absence a další měsíční vstupy. Běh uzavírejte až po kontrole. Platby, zaúčtování, dokumenty a podání jsou samostatné navazující kroky; samotný výpočet je neprovede.

Schválení běhu vytvoří neměnnou revizi. Pozdější změna živé karty zaměstnance,
účtu nebo politiky ji zpětně nepřepíše. Oprava proto vytváří navazující revizi
a u peněžních či účetních dopadů pouze rozdíl proti předchozímu stavu.

Úplné mzdy rozšiřují stejnou kartu zaměstnance, kterou používá Mzdová
rekapitulace; nezakládají druhý seznam osob. Ochrana období brání souběžnému
uzavření stejné firmy a měsíce v obou agendách.

## Kapitoly

1. [Absence a dovolená](58a_Absence_a_dovolena.md)
2. [Docházka a směny](58b_Dochazka_a_smeny.md)
3. [Cestovní náhrady](58c_Cestovni_nahrady.md)
4. [Rychlý měsíční vstup](58d_Rychly_mesicni_vstup.md)
5. [Mzdové běhy](58e_Mzdove_behy.md)
6. [Shoda účtování mezd](58f_Shoda_uctovani_mezd.md)
7. [Platby a úhrady](58g_Platby_a_uhrady.md)
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

Nejprve zkontrolujte aktivaci a podporovaný rozsah výše, potom [mzdové běhy](58e_Mzdove_behy.md) a [legislativní pravidla](58q_Legislativni_pravidla_mezd.md). Obecné diagnostické postupy jsou v kapitole [Řešení problémů](99_Reseni_problemu.md).
