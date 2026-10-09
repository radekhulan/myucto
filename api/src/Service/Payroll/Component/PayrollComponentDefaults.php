<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Component;

use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetException;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;

/**
 * Výchozí číselník mzdových složek — VERZOVANĚ.
 *
 * Tabulka dřív bydlela jako `PayrollComponentRepository::DEFAULTS` a zakládala se
 * s natvrdo napsaným `valid_from = '2026-01-01'`. To mělo dvě následky:
 *
 *  1. Legislativní změnu klasifikace nešlo do existujících firem rozvést vůbec —
 *     `INSERT IGNORE` narazil na unikátní klíč `(supplier_id, code, valid_from)`
 *     a tiše neudělal nic. Firma založená loni zůstala navždy na staré klasifikaci.
 *  2. Roční limit osvobození benefitů (`annual_limit_minor`) ve VÝČTU VKLÁDANÝCH
 *     SLOUPCŮ vůbec nebyl, takže zůstal NULL. `PayrollInputRepository::approve()`
 *     přitom limit hlídá jen tehdy, když NENÍ NULL — u výchozích složek se tedy
 *     roční strop nehlídal vůbec a benefit prošel v jakékoli výši.
 *
 * Verzuje se stejně jako podmínky pracovního vztahu a ruleset: nová verze VZNIKNE
 * VEDLE té staré a předchozí otevřené verzi se dopočítá `valid_to` na den před
 * účinností nové. Historie se nepřepisuje — mzdový vstup schválený loni si drží
 * `component_snapshot_json` a nadále ukazuje na verzi, která tehdy platila.
 *
 * ── Novou verzi, nebo opravu na místě? ──────────────────────────────────────
 * Rozhoduje se podle toho, ČÍ chyba se napravuje, ne podle toho, že se mění
 * hodnota ve sloupci:
 *
 *  - **Změnilo se právo od určitého dne** → NOVÁ VERZE s tímto `valid_from`.
 *    Stará klasifikace pro dřívější období PLATILA a musí zůstat čitelná,
 *    jinak by se zpětně přepsalo zacházení, které je zmrazené ve schválených
 *    vstupech a v revizích běhů.
 *  - **Klasifikace byla od začátku napsaná špatně** → OPRAVA ŘÁDKU NA MÍSTĚ
 *    v té verzi, kde vznikla. Zakládat novou verzi by tvrdilo, že do jejího
 *    dne platilo něco jiného — a to je nepravda: platilo totéž, jen to bylo
 *    v číselníku uvedené chybně. Tak to dělají migrace 1480, 1590 i 1610
 *    s řádky verze 2026-01-01 a je to správně.
 *
 * Oprava na místě je proto **jen pro dosud neúčinnou nebo právě probíhající
 * vlastní chybu**; jakmile se mění samo právo, verze se přidává.
 *
 * Zákonné částky se sem NEPÍŠOU a od migrace 1480 se ani nedosazují do složkového
 * stropu. Složka jen řekne, do KTERÉHO zákonného koše patří
 * ({@see PayrollBenefitExemptionBasket}); částku drží ruleset a limituje se ÚHRN
 * všech složek téhož koše za rok, ne jednotlivá složka. Nová průměrná mzda tedy
 * znamená nový ruleset, ne editaci čísla v kódu ani novou verzi klasifikace.
 *
 * `annual_limit_minor` zůstává výchozím složkám prázdný: je to vlastní strop
 * zaměstnavatele (tvrdá zábrana schválení), ne daňová hranice.
 */
final class PayrollComponentDefaults
{
    /**
     * Verze výchozí klasifikace, chronologicky. Sloupce řádku:
     *
     *  0 kód, 1 název, 2 druh složky, 3 peněžní/nepeněžní, 4 četnost,
     *  5 daň, 6 sociální (účast i vyměřovací základ), 7 zdravotní (účast
     *  i vyměřovací základ), 8 průměrný výdělek, 9 exekuční srážky, 10 JMHZ,
     *  11 statistika, 12 zákonný koš osvobození
     *     ({@see PayrollBenefitExemptionBasket}; NULL = složka do žádného koše
     *     nepatří, důvod je u konkrétního řádku),
     * 13 podklad osvobození ({@see PayrollExemptionBasis}; vyplněný jen u složky
     *     s daňovým zacházením `exempt`, jinak NULL).
     *
     * @var list<array{valid_from:string, rows:list<array{0:string,1:string,2:string,3:string,4:string,5:string,6:string,7:string,8:string,9:string,10:string,11:string,12:?string,13:?string}>}>
     */
    private const VERSIONS = [
        [
            'valid_from' => '2026-01-01',
            'rows' => [
                ['MZDA_MESICNI', 'Základní měsíční mzda', 'base_wage', 'monetary', 'regular', 'included', 'included', 'included', 'included', 'included', 'included', 'included', null, null],
                ['MZDA_HODINOVA', 'Základní hodinová mzda', 'hourly_wage', 'monetary', 'regular', 'included', 'included', 'included', 'included', 'included', 'included', 'included', null, null],
                ['MZDA_UKOLOVA', 'Úkolová mzda', 'task_wage', 'monetary', 'one_off', 'included', 'included', 'included', 'included', 'included', 'included', 'included', null, null],
                ['ODMENA', 'Odměna', 'bonus', 'monetary', 'one_off', 'included', 'included', 'included', 'included', 'included', 'included', 'included', null, null],
                ['PREMIE_PRIPLATKY', 'Prémie a příplatky', 'premium', 'monetary', 'one_off', 'included', 'included', 'included', 'included', 'included', 'included', 'included', null, null],
                // ── Zákonné příplatky § 114 až § 118 zákoníku práce ──────────────
                //
                // Vlastní kód pro každé ustanovení, ne jediná volná částka. Dokud
                // se všechno lilo do `PREMIE_PRIPLATKY`, nešlo z mzdového listu
                // doložit, KTERÝ zákonný nárok byl uspokojen a v jaké výši —
                // a přesně to po zaměstnavateli chce kontrola i sám zaměstnanec
                // (§ 142 odst. 5 ZP, písemný doklad o jednotlivých složkách mzdy).
                // Vlastní kód navíc dovoluje vlastní účetní předkontaci a vlastní
                // sloupec v přehledech, což u společné složky nešlo.
                //
                // Druh složky je `premium`, ne nový druh: příplatek JE mzda podle
                // § 109 odst. 2 a v číselníku druhů je `premium` právě to místo,
                // kde už `PREMIE_PRIPLATKY` sedí. Nový druh by znamenal `MODIFY
                // COLUMN` na `payroll_component_definitions.component_kind`,
                // a rozšiřovat databázový výčet kvůli tomu, co se od stávající
                // hodnoty ničím neliší, je zbytečné riziko.
                //
                // Klasifikace je u všech pěti shodná s běžnou mzdou: příplatek se
                // zdaňuje, je vyměřovacím základem obou pojistných, podléhá
                // exekučním srážkám a vstupuje do JMHZ.
                //
                // `average_earning` je `included` ZÁMĚRNĚ, ačkoli to vypadá jako
                // kruh (příplatek se z průměrného výdělku počítá a zároveň do něj
                // vstupuje). Kruh to není: § 353 zjišťuje průměrný výdělek
                // z hrubé mzdy ZA PŘEDCHOZÍ rozhodné období, tedy z jiného
                // čtvrtletí, než ve kterém se příplatek vyplácí. Vyloučit ho by
                // naopak znamenalo systematicky podhodnocovat průměr zaměstnanců
                // ve směnném provozu.
                ['PRIPLATEK_PRESCAS', 'Příplatek za práci přesčas', 'premium', 'monetary', 'one_off', 'included', 'included', 'included', 'included', 'included', 'included', 'included', null, null],
                ['PRIPLATEK_SVATEK', 'Příplatek za práci ve svátek', 'premium', 'monetary', 'one_off', 'included', 'included', 'included', 'included', 'included', 'included', 'included', null, null],
                ['PRIPLATEK_NOCNI', 'Příplatek za noční práci', 'premium', 'monetary', 'one_off', 'included', 'included', 'included', 'included', 'included', 'included', 'included', null, null],
                ['PRIPLATEK_VIKEND', 'Příplatek za práci v sobotu a v neděli', 'premium', 'monetary', 'one_off', 'included', 'included', 'included', 'included', 'included', 'included', 'included', null, null],
                ['PRIPLATEK_ZTIZENE_PROSTREDI', 'Příplatek za práci ve ztíženém pracovním prostředí', 'premium', 'monetary', 'one_off', 'included', 'included', 'included', 'included', 'included', 'included', 'included', null, null],
                // Odměna za pracovní pohotovost (§ 140 ZP). Není mzdou za práci
                // ani příplatkem § 114 až § 118, proto druh `allowance` a v JMHZ
                // vlastní atribut 10343 mimo mzdu zúčtovanou 10328. Zdaňuje se,
                // je vyměřovacím základem obou pojistných a podléhá exekučním
                // srážkám jako příjem postavený naroveň mzdě
                // ({@see \MyInvoice\Service\Payroll\Garnishment\GarnishableIncomeKind::StandbyRemuneration}).
                // Průměrný výdělek ji zahrnuje stejně jako příplatky; kdo to má
                // sjednané jinak, přeřadí to na složce.
                ['ODMENA_POHOTOVOST', 'Odměna za pracovní pohotovost', 'allowance', 'monetary', 'one_off', 'included', 'included', 'included', 'included', 'included', 'included', 'included', null, null],
                ['PROVIZE', 'Provize', 'commission', 'monetary', 'one_off', 'included', 'included', 'included', 'included', 'included', 'included', 'included', null, null],
                ['NAHRADA_MZDY', 'Náhrada mzdy', 'compensation', 'monetary', 'one_off', 'included', 'included', 'included', 'excluded', 'included', 'included', 'included', null, null],
                // Dovolená není odpracovaná doba ani část základní mzdy: za dobu
                // jejího čerpání přísluší podle § 222 odst. 1 ZP NÁHRADA mzdy ve
                // výši průměrného výdělku. Vlastní kód, ne obecná `NAHRADA_MZDY`,
                // ze dvou důvodů. Zaprvé § 142 odst. 5 ZP chce doklad o
                // JEDNOTLIVÝCH složkách mzdy, takže hodiny, sazba i částka musí
                // na pásce stát na vlastním řádku. Zadruhé měsíční hlášení má pro
                // ni samostatný atribut 10338 `mzda.nahrady.dovolena`, který
                // sběrná složka naplnit nedokáže.
                //
                // Klasifikace je shodná s obecnou náhradou mzdy: zdaňuje se,
                // vstupuje do obou vyměřovacích základů a podléhá exekučním
                // srážkám. `average_earning` je `excluded` — § 353 zjišťuje
                // průměrný výdělek z hrubé mzdy ZA ODPRACOVANOU DOBU, a náhrada
                // odpracovanou dobou není; zahrnout ji by znamenalo počítat
                // průměr z průměru.
                ['NAHRADA_MZDY_DOVOLENA', 'Náhrada mzdy za dovolenou', 'compensation', 'monetary', 'one_off', 'included', 'included', 'included', 'excluded', 'included', 'included', 'included', null, null],
                // Proplacená nebo vrácená náhrada za dovolenou zadaná částkou,
                // mimo čerpání z knihy dovolené (převod z jiného programu, PAMICA
                // J07/J10). Vrácení (§ 147 odst. 1 písm. e) ZP) je záporná částka
                // běžného měsíce ({@see \MyInvoice\Service\Payroll\Absence\VacationCompensationReturn}).
                // Klasifikace i kolonka 10338 jako u náhrady za dovolenou.
                ['NAHRADA_MZDY_DOVOLENA_VYROVNANI', 'Proplacená / vrácená náhrada za dovolenou', 'compensation', 'monetary', 'one_off', 'included', 'included', 'included', 'excluded', 'included', 'included', 'included', null, null],
                // Náhrady mzdy s vlastní kolonkou měsíčního hlášení: za svátek
                // (§ 115 odst. 3 ZP, 10339), při překážkách na straně
                // zaměstnavatele (§ 207 až § 210 ZP, 10340) a na straně
                // zaměstnance (§ 191 až § 206 ZP kromě DPN, 10341). Obecná
                // NAHRADA_MZDY je nerozliší, takže se dosud vykazovaly jen
                // v úhrnu 10337 a detail zůstával prázdný. Klasifikace je shodná
                // s obecnou náhradou mzdy.
                ['NAHRADA_MZDY_SVATEK', 'Náhrada mzdy za svátek', 'compensation', 'monetary', 'one_off', 'included', 'included', 'included', 'excluded', 'included', 'included', 'included', null, null],
                ['NAHRADA_MZDY_PREKAZKY_ZAMESTNAVATEL', 'Náhrada mzdy při překážkách na straně zaměstnavatele', 'compensation', 'monetary', 'one_off', 'included', 'included', 'included', 'excluded', 'included', 'included', 'included', null, null],
                ['NAHRADA_MZDY_PREKAZKY_ZAMESTNANEC', 'Náhrada mzdy při překážkách na straně zaměstnance', 'compensation', 'monetary', 'one_off', 'included', 'included', 'included', 'excluded', 'included', 'included', 'included', null, null],
                // Náhrada mzdy při DPN a karanténě je od daně OSVOBOZENÁ podle
                // § 6 odst. 9 písm. p) ZDP (do 31. 12. 2023 písm. t), viz NSS
                // 6 Ads 216/2023), a to do výše minimálního nároku § 192 odst. 2
                // ZP. Není to omyl, ač se to tak čte: zdanit by ji znamenalo
                // přeplatit daň. Jen nadstandardní část podle § 192 odst. 3 ZP
                // je zdanitelná, proto ji ruční vstup na tuhle složku nepustí
                // ({@see \MyInvoice\Repository\Payroll\PayrollInputRepository}).
                // Osvobozený příjem není vyměřovacím základem pojistného (§ 5
                // odst. 1 z. č. 589/1992 Sb., § 3 odst. 1 z. č. 592/1992 Sb.).
                // JMHZ a exekuční model zůstávají shodné
                // s obecnou náhradou mzdy; jejich odlišný režim by vyžadoval
                // samostatné zákonné pravidlo, které tento katalog neodhaduje.
                ['NAHRADA_MZDY_DPN', 'Náhrada mzdy při DPN', 'compensation', 'monetary', 'one_off', 'exempt', 'excluded', 'excluded', 'excluded', 'included', 'included', 'included', null, 'statutory_exempt'],
                ['ODSTUPNE', 'Odstupné', 'severance', 'monetary', 'one_off', 'included', 'excluded', 'excluded', 'excluded', 'included', 'included', 'included', null, null],
                // Jednorázová náhrada při skončení pracovního poměru pro
                // pracovní úraz nebo nemoc z povolání (§ 271ca ZP, 12× průměr).
                // Daň ANO: § 4 odst. 1 písm. d) bod 6 ZDP ji z osvobození
                // náhrad výslovně vylučuje, je to příjem podle § 6. Pojistné NE:
                // náhrada škody podle zákoníku práce není vyměřovacím základem
                // (§ 5 odst. 2 písm. a) z. č. 589/1992 Sb., § 3 odst. 2 písm. a)
                // z. č. 592/1992 Sb.). Průměrný výdělek NE (není to mzda za práci).
                // Srážkám podléhá jako „obdobné plnění poskytnuté v souvislosti
                // se skončením" (§ 299 odst. 1 písm. g) o. s. ř.), proto druh
                // `severance` — srážky se z ní počítají po násobcích stejně jako
                // z odstupného. V JMHZ patří do úhrnu zúčtovaného příjmu, ne do
                // rozpadu mzdy; zařazení je stejně jako u odstupného na účetní.
                ['NAHRADA_271CA', 'Jednorázová náhrada při skončení (§ 271ca ZP)', 'severance', 'monetary', 'one_off', 'included', 'excluded', 'excluded', 'excluded', 'included', 'included', 'included', null, null],
                ['NAHRADA_KONKURENCNI_DOLOZKA', 'Náhrada za konkurenční doložku', 'competitive_clause', 'monetary', 'one_off', 'manual_review', 'manual_review', 'manual_review', 'excluded', 'manual_review', 'manual_review', 'included', null, null],
                ['DOPLATEK_MZDY', 'Doplatek mzdy za minulé období', 'backpay', 'monetary', 'one_off', 'included', 'included', 'included', 'included', 'included', 'included', 'included', null, null],
                ['NEPENEZNI_PRIJEM', 'Nepeněžní příjem', 'non_cash', 'non_monetary', 'one_off', 'included', 'included', 'included', 'excluded', 'included', 'included', 'included', null, null],
                // Zdanitelná část závodního stravování: hodnota jídla nad osvobozený limit
                // § 6 odst. 9 písm. b) ZDP. Klasifikace je shodná s obecným nepeněžním
                // příjmem (zdaňuje se, vstupuje do obou vyměřovacích základů, nevyplácí se),
                // ale má vlastní kód, aby mohla mít výchozí zařazení do JMHZ. Obecný
                // NEPENEZNI_PRIJEM ho mít nesmí: nese i plnění, která do úhrnu zúčtované
                // mzdy nepatří, a default by je tam tiše přidal všem firmám.
                ['STRAVOVANI_ZDANITELNE', 'Zdanitelná část stravování', 'non_cash', 'non_monetary', 'one_off', 'included', 'included', 'included', 'excluded', 'included', 'included', 'included', null, null],
                // § 6 odst. 9 písm. b) ZDP. Limit je ZA SMĚNU (70 % horní hranice
                // stravného za cestu 5 až 12 hodin), ne za rok — roční strop složky
                // ho nevyjádří, proto ho drží koš `meal_per_shift`: ruleset dá
                // částku na jednu směnu a počet směn s nárokem dodá evidence
                // docházky ({@see PayrollMealShiftEvidenceService}). Do limitu se
                // neodvádí nic; nadlimitní část odbaví samostatná složka
                // `.nadlimit` ve výpočtu běhu, stejně jako u ročního koše.
                //
                // Do JMHZ složka PATŘÍ. Zúčtovaný příjem celkem (10286) je podle
                // datového slovníku nadmnožinou osvobozených příjmů (10289) —
                // kontrola 97 ČSSZ říká přímo „(10289) =< (10286)". Plnění
                // osvobozené podle § 6 odst. 9 je příjmem ze závislé činnosti,
                // jen se nezdaňuje, takže se do úhrnu započítává a v hlášení se
                // vykáže jako osvobozená část. Tím se liší od § 6 odst. 7, kde
                // plnění příjmem VŮBEC NENÍ (viz CESTOVNI_NAHRADA_LIMIT
                // a {@see PayrollExemptionBasis::NotSubjectToTax}) a do 10286
                // se nezapočítává.
                ['PRISPEVEK_STRAVOVANI', 'Příspěvek na stravování', 'benefit_meal', 'monetary', 'regular', 'exempt', 'excluded', 'excluded', 'excluded', 'excluded', 'included', 'included', 'meal_per_shift', 'periodic_benefit_limit'],
                // Příspěvek na stravování převzatý z jiného mzdového programu (PAMICA
                // Z21 „Stravenkový paušál"), rozdělený už ZDROJEM na osvobozenou část
                // a nadlimitní část. Limit za směnu tam spočítal program, který směny
                // evidoval; MyÚčto je u měsíce převzatého souhrnem nemá
                // ({@see PayrollMealShiftEvidenceService} by z prázdné docházky
                // spočítal nula nároků a zdanil celý příspěvek). Proto vlastní
                // jednorázová složka bez koše: osvobozená část je tu už doložená
                // rozpadem zdroje, stejně jako CESTOVNI_NAHRADA_LIMIT nese rozpad
                // vyúčtování cesty. Podklad `statutory_exempt` = § 6 odst. 9 ZDP,
                // nic se nedopočítává. Ruční vstup ji nezaloží
                // ({@see \MyInvoice\Repository\Payroll\PayrollInputRepository}),
                // jinak by obešla limit za směnu.
                // JMHZ: do 10286 i 10289 jako PRISPEVEK_STRAVOVANI, bez kolonky v rozpadu.
                ['PRISPEVEK_STRAVOVANI_PREVZATY', 'Příspěvek na stravování - osvobozená část', 'benefit_meal', 'monetary', 'one_off', 'exempt', 'excluded', 'excluded', 'excluded', 'excluded', 'included', 'included', null, 'statutory_exempt'],
                // Peněžitý příspěvek na stravování nad limit § 6 odst. 9 písm. b) ZDP
                // (a příspěvek poskytnutý bez osvobození, PAMICA Z21a): běžný zdanitelný
                // příjem a vyměřovací základ obou pojistných. Na rozdíl od
                // STRAVOVANI_ZDANITELNE se vyplácí, proto `monetary`. Průměrný výdělek
                // ani tady nezahrnuje, stejně jako osvobozenou část.
                ['PRISPEVEK_STRAVOVANI_ZDANITELNY', 'Příspěvek na stravování - zdanitelná část', 'benefit_meal', 'monetary', 'one_off', 'included', 'included', 'included', 'excluded', 'included', 'included', 'included', null, null],
                // § 6 odst. 9 písm. i) ZDP — hodnota přechodného ubytování do
                // 3 500 Kč měsíčně. Osvobozeno je jen NEPENĚŽNÍ plnění, jen mimo
                // pracovní cestu a jen tehdy, není-li obec přechodného ubytování
                // shodná s obcí bydliště zaměstnance. Obec ani účel plnění aplikace
                // v datech nemá; tyhle podmínky nese ZAŘAZENÍ složky, které volí
                // účetní, a aplikace hlídá to jediné, co spočítat umí — měsíční
                // strop.
                // Do JMHZ patří ze stejného důvodu jako příspěvek na stravování:
                // § 6 odst. 9 osvobozuje PŘÍJEM, nevyjímá ho z příjmů.
                ['PRECHODNE_UBYTOVANI', 'Přechodné ubytování zaměstnance', 'benefit_accommodation', 'non_monetary', 'regular', 'exempt', 'excluded', 'excluded', 'excluded', 'excluded', 'included', 'included', 'temporary_accommodation', 'periodic_benefit_limit'],
                // Soukromé užití vozidla je podle § 6 odst. 6 ZDP OCENĚNÍ příjmu
                // (1 % / 0,5 % / 0,25 % vstupní ceny měsíčně), ne osvobozený
                // benefit — žádný roční strop osvobození neexistuje.
                ['SOUKROME_VOZIDLO', 'Soukromé užití vozidla', 'benefit_vehicle', 'non_monetary', 'regular', 'included', 'included', 'included', 'excluded', 'included', 'included', 'included', null, null],
                ['PRISPEVEK_PENZE_ZIVOTNI', 'Příspěvek na penzijní a životní produkty', 'benefit_pension', 'monetary', 'regular', 'manual_review', 'manual_review', 'manual_review', 'excluded', 'manual_review', 'manual_review', 'included', 'old_age_savings', null],
                // § 6 odst. 9 písm. m) ZDP sdílí 50 000 Kč s příspěvkem na produkty
                // spoření na stáří, ale jde-li o jinou formu podpory dlouhodobé péče
                // než pojištění, spadá jinam. Zařazení tenhle číselník neurčí, takže
                // limit zůstává prázdný a vyplní ho účetní.
                ['PRISPEVEK_DLOUHODOBA_PECE', 'Příspěvek na dlouhodobou péči', 'benefit_care', 'monetary', 'regular', 'manual_review', 'manual_review', 'manual_review', 'excluded', 'manual_review', 'manual_review', 'included', null, null],
                // Vzdělávání má DVA různé režimy: odborný rozvoj související
                // s předmětem činnosti zaměstnavatele je podle § 6 odst. 9 písm. a)
                // ZDP osvobozený BEZ limitu, ostatní vzdělávání spadá pod strop
                // § 6 odst. 9 písm. d) bodu 2. Který z nich platí, plyne z náplně
                // kurzu — proto se tu limit netvrdí; naslepo nasazený strop by
                // blokoval schválení legitimního školení.
                ['VZDELAVANI', 'Vzdělávání zaměstnance', 'benefit_education', 'non_monetary', 'one_off', 'manual_review', 'manual_review', 'manual_review', 'excluded', 'manual_review', 'manual_review', 'included', null, null],
                ['REKREACE_VOLNY_CAS', 'Rekreace a volnočasový benefit', 'benefit_recreation', 'non_monetary', 'one_off', 'manual_review', 'manual_review', 'manual_review', 'excluded', 'manual_review', 'manual_review', 'included', 'non_cash_leisure', null],
                ['ZDRAVOTNI_BENEFIT', 'Zdravotní benefit', 'benefit_health', 'non_monetary', 'one_off', 'manual_review', 'manual_review', 'manual_review', 'excluded', 'manual_review', 'manual_review', 'included', 'non_cash_health', null],
                // Povinný příspěvek podle zákona č. 324/2025 Sb. je příspěvkem
                // zaměstnavatele na produkt spoření na stáří. Výši nevytváří
                // ruční mzdový vstup: runtime ji odvodí pouze ze schválené
                // evidence rozhodných směn a zmrazeného vyměřovacího základu.
                ['PRISPEVEK_RIZIKOVE_SPORENI', 'Povinný příspěvek na spoření u rizikové práce', 'risky_savings', 'non_monetary', 'regular', 'exempt', 'excluded', 'excluded', 'excluded', 'excluded', 'included', 'included', 'old_age_savings', 'benefit_basket'],
                ['CESTOVNI_NAHRADA', 'Cestovní náhrada', 'travel_reimbursement', 'monetary', 'one_off', 'manual_review', 'excluded', 'excluded', 'excluded', 'excluded', 'manual_review', 'included', null, null],
                // MZ-08-W07 — klasifikovaný rozpad vyúčtování pracovní cesty. Do zákonného
                // limitu (§ 6 odst. 7 písm. a) ZDP) není náhrada předmětem daně, pojistného,
                // průměrného výdělku ani exekučních srážek; nadlimitní část je běžný
                // zdanitelný příjem ze závislé činnosti a vstupuje do vyměřovacích základů.
                ['CESTOVNI_NAHRADA_LIMIT', 'Cestovní náhrada do zákonného limitu', 'travel_reimbursement', 'monetary', 'one_off', 'exempt', 'excluded', 'excluded', 'excluded', 'excluded', 'excluded', 'included', null, 'not_subject_to_tax'],
                ['CESTOVNI_NAHRADA_NADLIMIT', 'Nadlimitní cestovní náhrada', 'travel_reimbursement', 'monetary', 'one_off', 'included', 'included', 'included', 'excluded', 'included', 'included', 'included', null, null],
                // Odpočet poskytnuté zálohy při vyúčtování cesty (§ 183 ZP) —
                // ZÁPORNÁ částka. Záloha není příjem ani jeho snížení: nesmí
                // pohnout žádným základem ani výkazem, jen výplatou. Proto je
                // všude `excluded` a daňově stojí vedle nezdaněné náhrady, proti
                // které se započítává. Účetní protiúčet (pohledávka za
                // zaměstnancem, 335) doplní mzdový můstek podle kódu složky.
                ['CESTOVNI_NAHRADA_ZALOHA', 'Odpočet zálohy na pracovní cestu', 'travel_reimbursement', 'monetary', 'one_off', 'exempt', 'excluded', 'excluded', 'excluded', 'excluded', 'excluded', 'excluded', null, 'not_subject_to_tax'],
                // Náhrada výdajů převzatá z jiného mzdového programu (PAMICA „Náhrada
                // nezdaněná"), kterou zdroj vyplatil nad čistou mzdu jako plnění, které
                // není předmětem daně (§ 6 odst. 7 ZDP): mimo hrubou mzdu, vyměřovací
                // základy i úhrny JMHZ 10286 a 10289, stejně jako přijatá hlášení zdroje.
                // Jaký výdaj nahrazuje (opotřebení nářadí, práce z domova, cesta), export
                // nevede; posouzení udělal zdrojový program. Druh `other`, ne cestovní
                // náhrada, ať se neúčtuje jako cestovné. Ruční vstup ji nezaloží
                // ({@see \MyInvoice\Repository\Payroll\PayrollInputRepository}): vlastní
                // náhrady výdajů jdou přes cestovní náhrady, kde se limit dokládá.
                ['NAHRADA_VYDAJU_PREVZATA', 'Náhrada výdajů nepodléhající dani - převzatá', 'other', 'monetary', 'one_off', 'exempt', 'excluded', 'excluded', 'excluded', 'excluded', 'excluded', 'included', null, 'not_subject_to_tax'],
            ],
        ],
    ];

    /** @var list<array{valid_from:string, rows:list<array{0:string,1:string,2:string,3:string,4:string,5:string,6:string,7:string,8:string,9:string,10:string,11:string,12:?string,13:?string}>}> */
    private array $catalog;

    /**
     * @param list<array{valid_from:string, rows:list<array{0:string,1:string,2:string,3:string,4:string,5:string,6:string,7:string,8:string,9:string,10:string,11:string,12:?string,13:?string}>}>|null $catalog
     *        Jen pro testy verzování; runtime bere vestavěnou sadu.
     */
    public function __construct(
        private readonly PayrollRulesetProvider $rulesets,
        ?array $catalog = null,
    ) {
        $this->catalog = $catalog ?? self::VERSIONS;
    }

    /**
     * Kódy složek, které si aplikace zakládá sama, napříč všemi verzemi.
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        $codes = [];
        foreach (self::VERSIONS as $version) {
            foreach ($version['rows'] as $row) {
                $codes[$row[0]] = true;
            }
        }

        return array_keys($codes);
    }

    /**
     * Druh, četnost a daňové zacházení výchozí složky podle poslední verze, nebo
     * `null`, když kód do výchozího číselníku nepatří. Pro volající, kteří složku
     * jen pojmenují (profil importu) a zakládá ji číselník sám.
     *
     * @return array{component_kind:string, frequency_kind:string, tax_treatment:string, jmhz_treatment:string}|null
     */
    public static function classification(string $code): ?array
    {
        $found = null;
        foreach (self::VERSIONS as $version) {
            foreach ($version['rows'] as $row) {
                if ($row[0] === $code) {
                    $found = ['component_kind' => $row[2], 'frequency_kind' => $row[4], 'tax_treatment' => $row[5], 'jmhz_treatment' => $row[10]];
                }
            }
        }

        return $found;
    }

    /**
     * Klasifikace výchozí složky podle poslední verze ve tvaru definice složky, nebo
     * `null`, když kód do výchozího číselníku nepatří. Pro import, který složku
     * zakládá k dřívějšímu dni, než od kdy výchozí číselník platí (převod staršího
     * roku): bez ní by ji založil s obecným zdanitelným zacházením.
     *
     * @return array<string,?string>|null
     */
    public static function template(string $code): ?array
    {
        $found = null;
        foreach (self::VERSIONS as $version) {
            foreach ($version['rows'] as $row) {
                if ($row[0] === $code) {
                    $found = $row;
                }
            }
        }
        if ($found === null) {
            return null;
        }

        return [
            'name' => $found[1],
            'component_kind' => $found[2],
            'value_kind' => $found[3],
            'frequency_kind' => $found[4],
            'tax_treatment' => $found[5],
            'social_participation_treatment' => $found[6],
            'social_treatment' => $found[6],
            'health_participation_treatment' => $found[7],
            'health_treatment' => $found[7],
            'average_earning_treatment' => $found[8],
            'enforcement_treatment' => $found[9],
            'jmhz_treatment' => $found[10],
            'statistics_treatment' => $found[11],
            'exemption_basket' => $found[12],
            'exemption_basis' => $found[13],
        ];
    }

    /**
     * Verze klasifikace se zákonným košem osvobození u benefitních složek.
     *
     * Verze, ke které ruleset daně z příjmů nezná částku koše, se PŘESKOČÍ celá.
     * Založit složku s košem, jehož limit neexistuje, by znamenalo tiše vypnout
     * hlídání ročního stropu — přesně vada, kvůli které tahle třída vznikla.
     * Ostatní verze se založí normálně.
     *
     * @return list<array{valid_from:string, rows:list<array{
     *   code:string, name:string, component_kind:string, value_kind:string,
     *   frequency_kind:string, tax_treatment:string,
     *   social_treatment:string, health_treatment:string,
     *   average_earning_treatment:string, enforcement_treatment:string,
     *   jmhz_treatment:string, statistics_treatment:string,
     *   exemption_basket:?string, exemption_basis:?string
     * }>}>
     */
    public function versions(): array
    {
        $versions = [];
        foreach ($this->catalog as $version) {
            $rows = [];
            foreach ($version['rows'] as $row) {
                try {
                    $basket = $this->basket($row[12], $version['valid_from']);
                } catch (PayrollRulesetException) {
                    continue 2;
                }
                $rows[] = [
                    'code' => $row[0],
                    'name' => $row[1],
                    'component_kind' => $row[2],
                    'value_kind' => $row[3],
                    'frequency_kind' => $row[4],
                    'tax_treatment' => $row[5],
                    'social_treatment' => $row[6],
                    'health_treatment' => $row[7],
                    'average_earning_treatment' => $row[8],
                    'enforcement_treatment' => $row[9],
                    'jmhz_treatment' => $row[10],
                    'statistics_treatment' => $row[11],
                    'exemption_basket' => $basket?->value,
                    'exemption_basis' => $this->basis($row[13] ?? null, $row[5]),
                ];
            }
            $versions[] = ['valid_from' => $version['valid_from'], 'rows' => $rows];
        }
        usort(
            $versions,
            static fn (array $left, array $right): int => $left['valid_from'] <=> $right['valid_from'],
        );

        return $versions;
    }

    /**
     * Podklad osvobození ({@see PayrollExemptionBasis}) smí nést jen složka
     * klasifikovaná jako osvobozená — jinak by číselník tvrdil doklad k něčemu,
     * co se stejně zdaní.
     */
    private function basis(?string $value, string $taxTreatment): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($taxTreatment !== PayrollComponentTaxTreatment::EXEMPT->value
            || PayrollExemptionBasis::tryFrom($value) === null
        ) {
            throw new PayrollRulesetException(
                "Podklad osvobození {$value} nelze pro tuhle složku použít.",
            );
        }

        return $value;
    }

    /**
     * Ruleset se bere `forDate()`, ne `forCalculation()`: výchozí číselník je
     * jen SEED, který si firma smí přepsat, a nesmí spadnout jen proto, že
     * sada ještě není odborně schválená.
     *
     * Částka se z rulesetu jen OVĚŘÍ, do složky se nekopíruje — limit koše se
     * čte až ve chvíli výpočtu, aby nemohl zestárnout v číselníku.
     */
    private function basket(?string $value, string $validFrom): ?PayrollBenefitExemptionBasket
    {
        if ($value === null) {
            return null;
        }
        $basket = PayrollBenefitExemptionBasket::tryFrom($value);
        if ($basket === null) {
            throw new PayrollRulesetException(
                "Zákonný koš osvobození {$value} neexistuje.",
            );
        }
        $limit = $this->rulesets
            ->forDate(PayrollRulesetDomain::IncomeTax, $validFrom)
            ->parameter($basket->rulesetKey())
            ->value;
        if (!is_int($limit)) {
            throw new PayrollRulesetException(
                "Roční limit {$basket->rulesetKey()} není částka v haléřích.",
            );
        }

        return $basket;
    }
}
