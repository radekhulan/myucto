<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

/**
 * Směrná účtová osnova (Epic F1) — statická šablona syntetických 3místných účtů
 * dle vyhlášky 500/2002 Sb. (příloha č. 4). Naseeduje ji {@see ChartOfAccountsSeeder}
 * do chart_of_accounts per firma, když firma zapne double_entry.
 *
 * Každý řádek: [code, name, type, normal_side]
 *  - type:        asset | liability | equity | revenue | expense | offbalance
 *  - normal_side: 'debit' | 'credit' | null  (null = saldní/dvoustranný účet —
 *                 strana dle znaménka zůstatku, viz recon Section C/E)
 *
 * Účty korekce (oprávky 07x/08x/09x, opravné položky 19x/391) jsou stále
 * account_type = asset (korekční), ale normal_side = 'credit'. Aktivace 585–588
 * a snižovací účty mají normal_side proti své třídě. Podrozvahové 75x–79x jsou
 * offbalance (účtují se jednostranně proti 799).
 */
final class ChartOfAccountsTemplate
{
    /**
     * @var list<array{code:string,name:string,type:string,normal_side:?string,parent_code?:string}>
     */
    public const ACCOUNTS = [
        // ── Třída 0 — Dlouhodobý majetek ──────────────────────────────────
        ['code' => '012', 'name' => 'Nehmotné výsledky vývoje', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '013', 'name' => 'Software', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '014', 'name' => 'Ostatní ocenitelná práva', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '015', 'name' => 'Goodwill', 'type' => 'asset', 'normal_side' => null],
        ['code' => '019', 'name' => 'Ostatní dlouhodobý nehmotný majetek', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '021', 'name' => 'Stavby', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '022', 'name' => 'Hmotné movité věci a jejich soubory', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '025', 'name' => 'Pěstitelské celky trvalých porostů', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '026', 'name' => 'Dospělá zvířata a jejich skupiny', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '029', 'name' => 'Jiný dlouhodobý hmotný majetek', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '031', 'name' => 'Pozemky', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '032', 'name' => 'Umělecká díla a sbírky', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '041', 'name' => 'Nedokončený dlouhodobý nehmotný majetek', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '042', 'name' => 'Nedokončený dlouhodobý hmotný majetek', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '043', 'name' => 'Pořizovaný dlouhodobý finanční majetek', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '051', 'name' => 'Poskytnuté zálohy na dlouhodobý nehmotný majetek', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '052', 'name' => 'Poskytnuté zálohy na dlouhodobý hmotný majetek', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '053', 'name' => 'Poskytnuté zálohy na dlouhodobý finanční majetek', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '061', 'name' => 'Podíly — ovládaná nebo ovládající osoba', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '062', 'name' => 'Podíly — podstatný vliv', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '063', 'name' => 'Ostatní dlouhodobé cenné papíry a podíly', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '065', 'name' => 'Dluhové cenné papíry držené do splatnosti', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '066', 'name' => 'Zápůjčky a úvěry — ovládaná nebo ovládající osoba, podstatný vliv', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '067', 'name' => 'Ostatní zápůjčky a úvěry', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '069', 'name' => 'Jiný dlouhodobý finanční majetek', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '072', 'name' => 'Oprávky k nehmotným výsledkům vývoje', 'type' => 'asset', 'normal_side' => 'credit'],
        ['code' => '073', 'name' => 'Oprávky k softwaru', 'type' => 'asset', 'normal_side' => 'credit'],
        ['code' => '074', 'name' => 'Oprávky k ostatním ocenitelným právům', 'type' => 'asset', 'normal_side' => 'credit'],
        ['code' => '075', 'name' => 'Oprávky ke goodwillu', 'type' => 'asset', 'normal_side' => 'credit'],
        ['code' => '079', 'name' => 'Oprávky k ostatnímu dlouhodobému nehmotnému majetku', 'type' => 'asset', 'normal_side' => 'credit'],
        ['code' => '081', 'name' => 'Oprávky ke stavbám', 'type' => 'asset', 'normal_side' => 'credit'],
        ['code' => '082', 'name' => 'Oprávky k hmotným movitým věcem a jejich souborům', 'type' => 'asset', 'normal_side' => 'credit'],
        ['code' => '085', 'name' => 'Oprávky k pěstitelským celkům trvalých porostů', 'type' => 'asset', 'normal_side' => 'credit'],
        ['code' => '086', 'name' => 'Oprávky k dospělým zvířatům a jejich skupinám', 'type' => 'asset', 'normal_side' => 'credit'],
        ['code' => '089', 'name' => 'Oprávky k jinému dlouhodobému hmotnému majetku', 'type' => 'asset', 'normal_side' => 'credit'],
        ['code' => '091', 'name' => 'Opravná položka k dlouhodobému nehmotnému majetku', 'type' => 'asset', 'normal_side' => 'credit'],
        ['code' => '092', 'name' => 'Opravná položka k dlouhodobému hmotnému majetku', 'type' => 'asset', 'normal_side' => 'credit'],
        ['code' => '095', 'name' => 'Opravná položka k poskytnutým zálohám na dlouhodobý majetek', 'type' => 'asset', 'normal_side' => 'credit'],
        ['code' => '096', 'name' => 'Opravná položka k dlouhodobému finančnímu majetku', 'type' => 'asset', 'normal_side' => 'credit'],
        ['code' => '097', 'name' => 'Oceňovací rozdíl k nabytému majetku', 'type' => 'asset', 'normal_side' => null],
        ['code' => '098', 'name' => 'Oprávky k oceňovacímu rozdílu k nabytému majetku', 'type' => 'asset', 'normal_side' => 'credit'],

        // ── Třída 1 — Zásoby ──────────────────────────────────────────────
        ['code' => '111', 'name' => 'Pořízení materiálu', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '112', 'name' => 'Materiál na skladě', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '119', 'name' => 'Materiál na cestě', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '121', 'name' => 'Nedokončená výroba', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '122', 'name' => 'Polotovary vlastní výroby', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '123', 'name' => 'Výrobky', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '124', 'name' => 'Mladá a ostatní zvířata a jejich skupiny', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '131', 'name' => 'Pořízení zboží', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '132', 'name' => 'Zboží na skladě a v prodejnách', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '139', 'name' => 'Zboží na cestě', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '151', 'name' => 'Poskytnuté zálohy na materiál', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '152', 'name' => 'Poskytnuté zálohy na zvířata', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '153', 'name' => 'Poskytnuté zálohy na zboží', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '191', 'name' => 'Opravná položka k materiálu', 'type' => 'asset', 'normal_side' => 'credit'],
        ['code' => '192', 'name' => 'Opravná položka k nedokončené výrobě', 'type' => 'asset', 'normal_side' => 'credit'],
        ['code' => '193', 'name' => 'Opravná položka k polotovarům vlastní výroby', 'type' => 'asset', 'normal_side' => 'credit'],
        ['code' => '194', 'name' => 'Opravná položka k výrobkům', 'type' => 'asset', 'normal_side' => 'credit'],
        ['code' => '195', 'name' => 'Opravná položka ke zvířatům', 'type' => 'asset', 'normal_side' => 'credit'],
        ['code' => '196', 'name' => 'Opravná položka ke zboží', 'type' => 'asset', 'normal_side' => 'credit'],

        // ── Třída 2 — Krátkodobý finanční majetek a peněžní prostředky ─────
        ['code' => '211', 'name' => 'Pokladna', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '213', 'name' => 'Ceniny', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '221', 'name' => 'Peněžní prostředky na účtech', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '231', 'name' => 'Krátkodobé úvěry', 'type' => 'liability', 'normal_side' => 'credit'],
        ['code' => '232', 'name' => 'Eskontní úvěry', 'type' => 'liability', 'normal_side' => 'credit'],
        ['code' => '241', 'name' => 'Emitované krátkodobé dluhopisy', 'type' => 'liability', 'normal_side' => 'credit'],
        ['code' => '249', 'name' => 'Ostatní krátkodobé finanční výpomoci', 'type' => 'liability', 'normal_side' => 'credit'],
        ['code' => '251', 'name' => 'Majetkové cenné papíry k obchodování', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '253', 'name' => 'Dluhové cenné papíry k obchodování', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '256', 'name' => 'Dluhové cenné papíry se splatností do jednoho roku držené do splatnosti', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '257', 'name' => 'Ostatní cenné papíry', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '259', 'name' => 'Pořizovaný krátkodobý finanční majetek', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '261', 'name' => 'Peníze na cestě', 'type' => 'asset', 'normal_side' => null],

        // ── Třída 3 — Zúčtovací vztahy ────────────────────────────────────
        ['code' => '311', 'name' => 'Pohledávky z obchodních vztahů (odběratelé)', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '311D', 'name' => 'Dlouhodobé pohledávky z obchodních vztahů', 'type' => 'asset', 'normal_side' => 'debit', 'parent_code' => '311'],
        ['code' => '312', 'name' => 'Směnky k inkasu', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '313', 'name' => 'Pohledávky za eskontované cenné papíry', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '314', 'name' => 'Poskytnuté zálohy (krátkodobé i dlouhodobé, provozní)', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '315', 'name' => 'Ostatní pohledávky', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '321', 'name' => 'Závazky z obchodních vztahů (dodavatelé)', 'type' => 'liability', 'normal_side' => 'credit'],
        ['code' => '322', 'name' => 'Směnky k úhradě', 'type' => 'liability', 'normal_side' => 'credit'],
        ['code' => '324', 'name' => 'Přijaté provozní zálohy', 'type' => 'liability', 'normal_side' => 'credit'],
        ['code' => '325', 'name' => 'Ostatní závazky', 'type' => 'liability', 'normal_side' => 'credit'],
        ['code' => '331', 'name' => 'Zaměstnanci', 'type' => 'liability', 'normal_side' => 'credit'],
        ['code' => '333', 'name' => 'Ostatní závazky vůči zaměstnancům', 'type' => 'liability', 'normal_side' => 'credit'],
        ['code' => '335', 'name' => 'Pohledávky za zaměstnanci', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '336', 'name' => 'Zúčtování s institucemi sociálního zabezpečení a zdravotního pojištění', 'type' => 'liability', 'normal_side' => 'credit'],
        // Rozpad 336 na dvě instituce (migrace 1618). Syntetika pokrývá OBĚ, ale
        // dluží se dvěma různým věřitelům a platí dvěma příkazy — na společném
        // účtu se závazek vůči ČSSZ a vůči zdravotním pojišťovnám vzájemně
        // vynetuje a saldo proti platbám nesedí.
        //   336.100  sociální zabezpečení (ČSSZ / OSSZ)
        //   336.200  veřejné zdravotní pojištění (zdravotní pojišťovny)
        // Syntetika 336 v šabloně ZŮSTÁVÁ: firmy, které analytiku nechtějí, na ní
        // účtují dál a stávající zaúčtované mzdy se nemění.
        ['code' => '336.100', 'name' => 'Zúčtování se správou sociálního zabezpečení', 'type' => 'liability', 'normal_side' => 'credit', 'parent_code' => '336'],
        ['code' => '336.200', 'name' => 'Zúčtování se zdravotními pojišťovnami', 'type' => 'liability', 'normal_side' => 'credit', 'parent_code' => '336'],
        ['code' => '341', 'name' => 'Daň z příjmů', 'type' => 'liability', 'normal_side' => null],
        ['code' => '342', 'name' => 'Ostatní přímé daně', 'type' => 'liability', 'normal_side' => 'credit'],
        // Rozpad 342 na zálohovou a srážkovou daň ze závislé činnosti (migrace 1648).
        // Obě jsou daní téhož poplatníka u téhož správce daně, ale odvádějí se DVĚMA
        // platbami (předčíslí 7704 vs. 7720), v jiných termínech a vykazují se jiným
        // hlášením (vyúčtování § 38j vs. § 38d odst. 3 ZDP). Na společném účtu se
        // závazky slijí a rozdíl mezi saldem a odvedenými platbami nejde přiřadit
        // k jedné z daní.
        //   342.100  záloha na daň ze závislé činnosti (včetně bonusů a ročního zúčtování)
        //   342.200  srážková daň zvláštní sazbou
        // Syntetika 342 v šabloně ZŮSTÁVÁ: firmy, které analytiku nechtějí, na ní
        // účtují dál a stávající zaúčtované mzdy se nemění.
        ['code' => '342.100', 'name' => 'Záloha na daň ze závislé činnosti', 'type' => 'liability', 'normal_side' => 'credit', 'parent_code' => '342'],
        ['code' => '342.200', 'name' => 'Srážková daň ze závislé činnosti', 'type' => 'liability', 'normal_side' => 'credit', 'parent_code' => '342'],
        ['code' => '343', 'name' => 'Daň z přidané hodnoty', 'type' => 'liability', 'normal_side' => null],
        // Rozpad DPH na vstup / výstup / zúčtování (migrace 1323). Účetní vede daň takhle
        // a bez toho nejde na konci období udělat interní doklad, který obrat období převede
        // na zúčtovací účet — na plochém 343 by se vstup s výstupem hned vzájemně vynetoval
        // a zůstatek by nešlo odsouhlasit proti přiznání ani proti úhradě na FÚ.
        //   343.100  daň na VSTUPU  (nárok na odpočet, obvykle MD)
        //   343.200  daň na VÝSTUPU (povinnost přiznat, obvykle D)
        //   343.900  ZÚČTOVÁNÍ s FÚ (po interním dokladu nese celý závazek/nadměrný odpočet
        //            období; vynuluje ho až platba/vratka z banky)
        // normal_side zůstává NULL (saldní účet) — každá z analytik může být z principu na
        // obou stranách (dobropis, opravný DDKP, nadměrný odpočet).
        ['code' => '343.100', 'name' => 'Daň z přidané hodnoty vstup', 'type' => 'liability', 'normal_side' => null, 'parent_code' => '343'],
        ['code' => '343.200', 'name' => 'Daň z přidané hodnoty výstup', 'type' => 'liability', 'normal_side' => null, 'parent_code' => '343'],
        ['code' => '343.900', 'name' => 'Daň z přidané hodnoty zúčtování', 'type' => 'liability', 'normal_side' => null, 'parent_code' => '343'],
        ['code' => '345', 'name' => 'Ostatní daně a poplatky', 'type' => 'liability', 'normal_side' => 'credit'],
        // OSS (§ 110 a násl. ZDPH): daň patří státu spotřeby, ne českému rozpočtu — do
        // přiznání k DPH ani do KH nevstupuje, platí se zvlášť a v jiné měně. Kdyby seděla
        // na 343, zůstatek 343 by se s přiznáním z principu nemohl srovnat. Analytika k 345
        // (ostatní daně) drží 343 čisté; v rozvaze je to táž položka „Stát — daňové závazky"
        // (mapa výkazů matchuje na prefix, takže 345.100 padne pod 345 bez dalšího zásahu).
        ['code' => '345.100', 'name' => 'DPH v režimu OSS — jiný členský stát', 'type' => 'liability', 'normal_side' => 'credit', 'parent_code' => '345'],
        ['code' => '346', 'name' => 'Dotace ze státního rozpočtu', 'type' => 'liability', 'normal_side' => 'credit'],
        ['code' => '351', 'name' => 'Pohledávky — ovládaná nebo ovládající osoba', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '353', 'name' => 'Pohledávky za upsaný základní kapitál', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '355', 'name' => 'Ostatní pohledávky za společníky obchodní korporace', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '361', 'name' => 'Závazky — ovládaná nebo ovládající osoba', 'type' => 'liability', 'normal_side' => 'credit'],
        ['code' => '364', 'name' => 'Závazky ke společníkům obchodní korporace při rozdělování zisku', 'type' => 'liability', 'normal_side' => 'credit'],
        ['code' => '365', 'name' => 'Ostatní závazky ke společníkům obchodní korporace', 'type' => 'liability', 'normal_side' => 'credit'],
        ['code' => '366', 'name' => 'Závazky ke společníkům obchodní korporace ze závislé činnosti', 'type' => 'liability', 'normal_side' => 'credit'],
        ['code' => '378', 'name' => 'Jiné pohledávky', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '379', 'name' => 'Jiné závazky', 'type' => 'liability', 'normal_side' => 'credit'],
        // Rozpad 379 na tři mzdové závazky (migrace 1658). Syntetika nesla dobrovolné
        // srážky, exekuce i příspěvek na spoření u rizikové práce najednou, přestože
        // se platí TŘEM různým skupinám věřitelů (oprávněný z dohody o srážkách,
        // soudní exekutor / insolvenční správce, penzijní společnost). Na společném
        // účtu se salda slila a zůstatek nešlo odsouhlasit proti žádné z plateb.
        //   379.100  dobrovolné srážky ze mzdy (§ 146 písm. b) zákoníku práce)
        //   379.200  exekuční a insolvenční srážky (§ 276 a násl. o. s. ř.)
        //   379.300  povinný příspěvek na spoření u rizikové práce (z. č. 324/2025 Sb.)
        // Syntetika 379 v šabloně ZŮSTÁVÁ: firmy, které analytiku nechtějí, na ní
        // účtují dál a stávající zaúčtované mzdy se nemění.
        ['code' => '379.100', 'name' => 'Srážky ze mzdy', 'type' => 'liability', 'normal_side' => 'credit', 'parent_code' => '379'],
        ['code' => '379.200', 'name' => 'Exekuční a insolvenční srážky', 'type' => 'liability', 'normal_side' => 'credit', 'parent_code' => '379'],
        ['code' => '379.300', 'name' => 'Spoření u rizikové práce', 'type' => 'liability', 'normal_side' => 'credit', 'parent_code' => '379'],
        ['code' => '381', 'name' => 'Náklady příštích období', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '382', 'name' => 'Komplexní náklady příštích období', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '383', 'name' => 'Výdaje příštích období', 'type' => 'liability', 'normal_side' => 'credit'],
        ['code' => '384', 'name' => 'Výnosy příštích období', 'type' => 'liability', 'normal_side' => 'credit'],
        ['code' => '385', 'name' => 'Příjmy příštích období', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '388', 'name' => 'Dohadné účty aktivní', 'type' => 'asset', 'normal_side' => 'debit'],
        ['code' => '389', 'name' => 'Dohadné účty pasivní', 'type' => 'liability', 'normal_side' => 'credit'],
        ['code' => '391', 'name' => 'Opravná položka k pohledávkám', 'type' => 'asset', 'normal_side' => 'credit'],
        ['code' => '395', 'name' => 'Vnitřní zúčtování', 'type' => 'asset', 'normal_side' => null],

        // ── Třída 4 — Kapitálové účty a dlouhodobé závazky ─────────────────
        ['code' => '411', 'name' => 'Základní kapitál', 'type' => 'equity', 'normal_side' => 'credit'],
        ['code' => '412', 'name' => 'Ážio', 'type' => 'equity', 'normal_side' => 'credit'],
        ['code' => '413', 'name' => 'Ostatní kapitálové fondy', 'type' => 'equity', 'normal_side' => 'credit'],
        ['code' => '414', 'name' => 'Oceňovací rozdíly z přecenění majetku a závazků', 'type' => 'equity', 'normal_side' => null],
        ['code' => '419', 'name' => 'Změny základního kapitálu', 'type' => 'equity', 'normal_side' => null],
        ['code' => '421', 'name' => 'Rezervní fond', 'type' => 'equity', 'normal_side' => 'credit'],
        ['code' => '423', 'name' => 'Statutární fondy', 'type' => 'equity', 'normal_side' => 'credit'],
        ['code' => '427', 'name' => 'Ostatní fondy', 'type' => 'equity', 'normal_side' => 'credit'],
        ['code' => '428', 'name' => 'Nerozdělený zisk minulých let', 'type' => 'equity', 'normal_side' => 'credit'],
        ['code' => '429', 'name' => 'Neuhrazená ztráta minulých let', 'type' => 'equity', 'normal_side' => 'debit'],
        ['code' => '431', 'name' => 'Výsledek hospodaření ve schvalovacím řízení', 'type' => 'equity', 'normal_side' => null],
        ['code' => '432', 'name' => 'Rozhodnuto o zálohové výplatě podílu na zisku', 'type' => 'equity', 'normal_side' => 'debit'],
        ['code' => '451', 'name' => 'Rezervy podle zvláštních právních předpisů (zákonné)', 'type' => 'liability', 'normal_side' => 'credit'],
        ['code' => '453', 'name' => 'Rezerva na daň z příjmů', 'type' => 'liability', 'normal_side' => 'credit'],
        ['code' => '459', 'name' => 'Ostatní rezervy', 'type' => 'liability', 'normal_side' => 'credit'],
        ['code' => '461', 'name' => 'Dlouhodobé závazky k úvěrovým institucím', 'type' => 'liability', 'normal_side' => 'credit'],
        ['code' => '461K', 'name' => 'Krátkodobá část dlouhodobých úvěrů', 'type' => 'liability', 'normal_side' => 'credit', 'parent_code' => '461'],
        ['code' => '471', 'name' => 'Dlouhodobé závazky — ovládaná nebo ovládající osoba', 'type' => 'liability', 'normal_side' => 'credit'],
        ['code' => '473', 'name' => 'Emitované dluhopisy', 'type' => 'liability', 'normal_side' => 'credit'],
        ['code' => '474', 'name' => 'Závazky z pachtu', 'type' => 'liability', 'normal_side' => 'credit'],
        ['code' => '475', 'name' => 'Dlouhodobé přijaté zálohy', 'type' => 'liability', 'normal_side' => 'credit'],
        ['code' => '479', 'name' => 'Jiné dlouhodobé závazky', 'type' => 'liability', 'normal_side' => 'credit'],
        ['code' => '481', 'name' => 'Odložený daňový závazek a pohledávka', 'type' => 'liability', 'normal_side' => null],
        ['code' => '491', 'name' => 'Účet individuálního podnikatele', 'type' => 'equity', 'normal_side' => null],

        // ── Třída 5 — Náklady ─────────────────────────────────────────────
        ['code' => '501', 'name' => 'Spotřeba materiálu', 'type' => 'expense', 'normal_side' => 'debit'],
        // Analytiky PHM / servis vozidel — default pro KAŽDOU firmu (migrace 1127 je
        // zavedla existujícím tenantům, tady je dostávají i nová nasazení). Jakmile má
        // syntetika potomky, nesmí se na ni účtovat → proto i „zbytkové" .900 a override
        // předkontace v ChartOfAccountsSeeder::seedAnalyticPostingRules().
        ['code' => '501.100', 'name' => 'PHM — pohonné hmoty', 'type' => 'expense', 'normal_side' => 'debit', 'parent_code' => '501'],
        ['code' => '501.900', 'name' => 'Ostatní materiál', 'type' => 'expense', 'normal_side' => 'debit', 'parent_code' => '501'],
        ['code' => '501.990', 'name' => 'Spotřeba materiálu — daňově neuznatelné', 'type' => 'expense', 'normal_side' => 'debit', 'parent_code' => '501'],
        ['code' => '502', 'name' => 'Spotřeba energie', 'type' => 'expense', 'normal_side' => 'debit'],
        ['code' => '503', 'name' => 'Spotřeba ostatních neskladovatelných dodávek', 'type' => 'expense', 'normal_side' => 'debit'],
        ['code' => '504', 'name' => 'Prodané zboží', 'type' => 'expense', 'normal_side' => 'debit'],
        ['code' => '511', 'name' => 'Opravy a udržování', 'type' => 'expense', 'normal_side' => 'debit'],
        ['code' => '511.100', 'name' => 'Servis a opravy vozidel', 'type' => 'expense', 'normal_side' => 'debit', 'parent_code' => '511'],
        ['code' => '511.900', 'name' => 'Ostatní opravy', 'type' => 'expense', 'normal_side' => 'debit', 'parent_code' => '511'],
        ['code' => '511.990', 'name' => 'Opravy a udržování — daňově neuznatelné', 'type' => 'expense', 'normal_side' => 'debit', 'parent_code' => '511'],
        ['code' => '512', 'name' => 'Cestovné', 'type' => 'expense', 'normal_side' => 'debit'],
        ['code' => '513', 'name' => 'Náklady na reprezentaci', 'type' => 'expense', 'normal_side' => 'debit'],
        ['code' => '518', 'name' => 'Ostatní služby', 'type' => 'expense', 'normal_side' => 'debit'],
        ['code' => '518.990', 'name' => 'Ostatní služby — daňově neuznatelné', 'type' => 'expense', 'normal_side' => 'debit', 'parent_code' => '518'],
        ['code' => '521', 'name' => 'Mzdové náklady', 'type' => 'expense', 'normal_side' => 'debit'],
        ['code' => '522', 'name' => 'Příjmy společníků obchodní korporace ze závislé činnosti', 'type' => 'expense', 'normal_side' => 'debit'],
        ['code' => '523', 'name' => 'Odměny členům orgánů obchodní korporace', 'type' => 'expense', 'normal_side' => 'debit'],
        ['code' => '524', 'name' => 'Zákonné sociální a zdravotní pojištění', 'type' => 'expense', 'normal_side' => 'debit'],
        ['code' => '525', 'name' => 'Ostatní sociální pojištění', 'type' => 'expense', 'normal_side' => 'debit'],
        ['code' => '526', 'name' => 'Sociální náklady individuálního podnikatele', 'type' => 'expense', 'normal_side' => 'debit'],
        ['code' => '527', 'name' => 'Zákonné sociální náklady', 'type' => 'expense', 'normal_side' => 'debit'],
        ['code' => '528', 'name' => 'Ostatní sociální náklady', 'type' => 'expense', 'normal_side' => 'debit'],
        ['code' => '531', 'name' => 'Daň silniční', 'type' => 'expense', 'normal_side' => 'debit'],
        ['code' => '532', 'name' => 'Daň z nemovitých věcí', 'type' => 'expense', 'normal_side' => 'debit'],
        ['code' => '538', 'name' => 'Ostatní daně a poplatky', 'type' => 'expense', 'normal_side' => 'debit'],
        ['code' => '541', 'name' => 'Zůstatková cena prodaného dlouhodobého majetku', 'type' => 'expense', 'normal_side' => 'debit'],
        ['code' => '542', 'name' => 'Prodaný materiál', 'type' => 'expense', 'normal_side' => 'debit'],
        ['code' => '543', 'name' => 'Dary', 'type' => 'expense', 'normal_side' => 'debit'],
        ['code' => '544', 'name' => 'Smluvní pokuty a úroky z prodlení', 'type' => 'expense', 'normal_side' => 'debit'],
        ['code' => '545', 'name' => 'Ostatní pokuty a penále', 'type' => 'expense', 'normal_side' => 'debit'],
        ['code' => '546', 'name' => 'Odpis pohledávky', 'type' => 'expense', 'normal_side' => 'debit'],
        ['code' => '548', 'name' => 'Ostatní provozní náklady', 'type' => 'expense', 'normal_side' => 'debit'],
        ['code' => '548.990', 'name' => 'Ostatní provozní náklady — daňově neuznatelné', 'type' => 'expense', 'normal_side' => 'debit', 'parent_code' => '548'],
        ['code' => '549', 'name' => 'Manka a škody z provozní činnosti', 'type' => 'expense', 'normal_side' => 'debit'],
        ['code' => '551', 'name' => 'Odpisy dlouhodobého nehmotného a hmotného majetku', 'type' => 'expense', 'normal_side' => 'debit'],
        ['code' => '552', 'name' => 'Tvorba a zúčtování zákonných rezerv', 'type' => 'expense', 'normal_side' => null],
        ['code' => '554', 'name' => 'Tvorba a zúčtování ostatních rezerv', 'type' => 'expense', 'normal_side' => null],
        ['code' => '555', 'name' => 'Tvorba a zúčtování komplexních nákladů příštích období', 'type' => 'expense', 'normal_side' => null],
        ['code' => '557', 'name' => 'Zúčtování oprávky k oceňovacímu rozdílu k nabytému majetku', 'type' => 'expense', 'normal_side' => 'debit'],
        ['code' => '558', 'name' => 'Tvorba a zúčtování zákonných opravných položek', 'type' => 'expense', 'normal_side' => null],
        ['code' => '559', 'name' => 'Tvorba a zúčtování opravných položek (účetních)', 'type' => 'expense', 'normal_side' => null],
        ['code' => '559M', 'name' => 'Opravné položky k dlouhodobému majetku', 'type' => 'expense', 'normal_side' => 'debit', 'parent_code' => '559'],
        ['code' => '559Z', 'name' => 'Opravné položky k zásobám', 'type' => 'expense', 'normal_side' => 'debit', 'parent_code' => '559'],
        ['code' => '559P', 'name' => 'Opravné položky k pohledávkám', 'type' => 'expense', 'normal_side' => 'debit', 'parent_code' => '559'],
        ['code' => '561', 'name' => 'Prodané cenné papíry a podíly', 'type' => 'expense', 'normal_side' => 'debit'],
        ['code' => '561P', 'name' => 'Prodané podíly', 'type' => 'expense', 'normal_side' => 'debit', 'parent_code' => '561'],
        ['code' => '561C', 'name' => 'Prodané ostatní cenné papíry', 'type' => 'expense', 'normal_side' => 'debit', 'parent_code' => '561'],
        ['code' => '562', 'name' => 'Úroky', 'type' => 'expense', 'normal_side' => 'debit'],
        ['code' => '563', 'name' => 'Kurzové ztráty', 'type' => 'expense', 'normal_side' => 'debit'],
        ['code' => '564', 'name' => 'Náklady z přecenění cenných papírů', 'type' => 'expense', 'normal_side' => 'debit'],
        ['code' => '566', 'name' => 'Náklady z finančního majetku', 'type' => 'expense', 'normal_side' => 'debit'],
        ['code' => '567', 'name' => 'Náklady z derivátových operací', 'type' => 'expense', 'normal_side' => 'debit'],
        ['code' => '568', 'name' => 'Ostatní finanční náklady', 'type' => 'expense', 'normal_side' => 'debit'],
        ['code' => '569', 'name' => 'Manka a škody na finančním majetku', 'type' => 'expense', 'normal_side' => 'debit'],
        ['code' => '574', 'name' => 'Tvorba a zúčtování finančních rezerv', 'type' => 'expense', 'normal_side' => null],
        ['code' => '581', 'name' => 'Změna stavu nedokončené výroby', 'type' => 'expense', 'normal_side' => null],
        ['code' => '582', 'name' => 'Změna stavu polotovarů vlastní výroby', 'type' => 'expense', 'normal_side' => null],
        ['code' => '583', 'name' => 'Změna stavu výrobků', 'type' => 'expense', 'normal_side' => null],
        ['code' => '584', 'name' => 'Změna stavu zvířat', 'type' => 'expense', 'normal_side' => null],
        ['code' => '585', 'name' => 'Aktivace materiálu a zboží', 'type' => 'expense', 'normal_side' => 'credit'],
        ['code' => '586', 'name' => 'Aktivace vnitropodnikových služeb', 'type' => 'expense', 'normal_side' => 'credit'],
        ['code' => '587', 'name' => 'Aktivace dlouhodobého nehmotného majetku', 'type' => 'expense', 'normal_side' => 'credit'],
        ['code' => '588', 'name' => 'Aktivace dlouhodobého hmotného majetku', 'type' => 'expense', 'normal_side' => 'credit'],
        ['code' => '591', 'name' => 'Daň z příjmů — splatná', 'type' => 'expense', 'normal_side' => 'debit'],
        ['code' => '592', 'name' => 'Daň z příjmů — odložená', 'type' => 'expense', 'normal_side' => null],
        ['code' => '595', 'name' => 'Dodatečné odvody daně z příjmů', 'type' => 'expense', 'normal_side' => 'debit'],
        ['code' => '596', 'name' => 'Převod podílu na výsledku hospodaření společníkům', 'type' => 'expense', 'normal_side' => null],
        ['code' => '599', 'name' => 'Tvorba a zúčtování rezervy na daň z příjmů', 'type' => 'expense', 'normal_side' => null],

        // ── Třída 6 — Výnosy ──────────────────────────────────────────────
        ['code' => '601', 'name' => 'Tržby za vlastní výrobky', 'type' => 'revenue', 'normal_side' => 'credit'],
        ['code' => '602', 'name' => 'Tržby z prodeje služeb', 'type' => 'revenue', 'normal_side' => 'credit'],
        ['code' => '604', 'name' => 'Tržby za zboží', 'type' => 'revenue', 'normal_side' => 'credit'],
        ['code' => '641', 'name' => 'Tržby z prodeje dlouhodobého nehmotného a hmotného majetku', 'type' => 'revenue', 'normal_side' => 'credit'],
        ['code' => '642', 'name' => 'Tržby z prodeje materiálu', 'type' => 'revenue', 'normal_side' => 'credit'],
        ['code' => '644', 'name' => 'Smluvní pokuty a úroky z prodlení', 'type' => 'revenue', 'normal_side' => 'credit'],
        ['code' => '646', 'name' => 'Výnosy z odepsaných pohledávek', 'type' => 'revenue', 'normal_side' => 'credit'],
        ['code' => '648', 'name' => 'Ostatní provozní výnosy', 'type' => 'revenue', 'normal_side' => 'credit'],
        ['code' => '661', 'name' => 'Tržby z prodeje cenných papírů a podílů', 'type' => 'revenue', 'normal_side' => 'credit'],
        ['code' => '662', 'name' => 'Úroky', 'type' => 'revenue', 'normal_side' => 'credit'],
        ['code' => '663', 'name' => 'Kurzové zisky', 'type' => 'revenue', 'normal_side' => 'credit'],
        ['code' => '664', 'name' => 'Výnosy z přecenění cenných papírů', 'type' => 'revenue', 'normal_side' => 'credit'],
        ['code' => '665', 'name' => 'Výnosy z dlouhodobého finančního majetku', 'type' => 'revenue', 'normal_side' => 'credit'],
        ['code' => '666', 'name' => 'Výnosy z krátkodobého finančního majetku', 'type' => 'revenue', 'normal_side' => 'credit'],
        ['code' => '668', 'name' => 'Ostatní finanční výnosy', 'type' => 'revenue', 'normal_side' => 'credit'],

        // ── Třída 7 — Závěrkové a podrozvahové účty ────────────────────────
        ['code' => '701', 'name' => 'Počáteční účet rozvažný', 'type' => 'closing', 'normal_side' => null],
        ['code' => '702', 'name' => 'Konečný účet rozvažný', 'type' => 'closing', 'normal_side' => null],
        ['code' => '710', 'name' => 'Účet zisků a ztrát', 'type' => 'closing', 'normal_side' => null],
        ['code' => '751', 'name' => 'Najatý a propachtovaný majetek', 'type' => 'offbalance', 'normal_side' => 'debit'],
        ['code' => '752', 'name' => 'Majetek přijatý do úschovy', 'type' => 'offbalance', 'normal_side' => 'debit'],
        ['code' => '761', 'name' => 'Odepsané pohledávky v evidenci', 'type' => 'offbalance', 'normal_side' => 'debit'],
        ['code' => '771', 'name' => 'Poskytnutá zajištění a zástavy', 'type' => 'offbalance', 'normal_side' => 'debit'],
        ['code' => '772', 'name' => 'Přijatá zajištění a zástavy', 'type' => 'offbalance', 'normal_side' => 'debit'],
        ['code' => '781', 'name' => 'Přísliby a smluvní závazky nevykázané v rozvaze', 'type' => 'offbalance', 'normal_side' => 'debit'],
        ['code' => '791', 'name' => 'Ostatní podrozvahová evidence', 'type' => 'offbalance', 'normal_side' => 'debit'],
        ['code' => '799', 'name' => 'Podrozvahový protiúčet (vyrovnávací)', 'type' => 'offbalance', 'normal_side' => 'credit'],
    ];

    /**
     * Daňově NEuznatelné nákladové syntetiky dle §25 ZDP (Epic DP, issue #18).
     * Analytiky dědí ze syntetiky (klasifikace přes LEFT(account_code, 3)).
     * Zdroj pravdy pro seed {@see ChartOfAccountsSeeder} i pro migraci 1030;
     * uživatel může přepnout ručně v osnově (sloupec chart_of_accounts.tax_deductibility).
     *
     * Vědomě NEobsahuje: 551 odpisy (řeší rozdíl daňových/účetních odpisů ř. 50/150),
     * 59x daň z příjmů (do VH před zdaněním nevstupuje).
     *
     * @var list<string>
     */
    public const NON_DEDUCTIBLE_SYNTHETICS = ['513', '528', '543', '545', '549', '554', '559'];

    /**
     * Nedaňové analytiky zachovávající věcný druh nákladu. Automatika je použije,
     * když je celý přijatý doklad označen jako daňově neuznatelný a konkrétnější
     * nedaňový účet (513, 528, 543…) nebyl určen pravidlem nebo účetní.
     *
     * @var array<string,string> syntetika => nedaňová analytika
     */
    public const NON_DEDUCTIBLE_ANALYTICS = [
        '501' => '501.990',
        '511' => '511.990',
        '518' => '518.990',
        '548' => '548.990',
    ];

    /**
     * Daňová uznatelnost účtu. Explicitní nedaňové analytiky mají přednost,
     * ostatní analytiky dědí klasifikaci z prvních tří znaků syntetiky.
     */
    public static function taxDeductibility(string $accountCode): string
    {
        $synthetic = substr($accountCode, 0, 3);
        return in_array($accountCode, self::NON_DEDUCTIBLE_ANALYTICS, true)
            || in_array($synthetic, self::NON_DEDUCTIBLE_SYNTHETICS, true)
            ? 'non_deductible'
            : 'deductible';
    }

    public static function nonDeductibleAnalyticFor(string $accountCode): ?string
    {
        return self::NON_DEDUCTIBLE_ANALYTICS[substr($accountCode, 0, 3)] ?? null;
    }

    /**
     * Počet účtů v šabloně (pro testy / sanity check).
     */
    public static function count(): int
    {
        return count(self::ACCOUNTS);
    }
}
