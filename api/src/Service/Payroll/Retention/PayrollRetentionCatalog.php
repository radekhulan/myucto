<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Retention;

use MyInvoice\Service\Accounting\RetentionPolicy;

/**
 * Zákonné retenční lhůty mzdové agendy jako DATA, ne jako konstanta zapadlá
 * v mazací rutině. Přímý protějšek {@see \MyInvoice\Service\Payroll\Deadline\PayrollLevyDeadlinePolicy}:
 * stejný tvar (hodnota + citace + stav doloženosti), stejný důvod.
 *
 * ── Proč katalog v kódu a ne v tabulce ────────────────────────────────────────
 * Zákonná lhůta je tvrzení o právu, ne nastavení zákazníka. Musí projít revizí
 * v diffu — lhůta, kterou jde tiše přepsat UPDATEem, je horší než ta, co
 * vyžaduje commit, protože podle ní se NEVRATNĚ maže. Tenantní odchylky (delší
 * lhůta ze smlouvy, dodaná lhůta tam, kde zákon mlčí) proto žijí v tabulce
 * `payroll_retention_policies` a katalog jen PŘEBÍJEJÍ SMĚREM NAHORU — viz
 * {@see \MyInvoice\Repository\Payroll\PayrollRetentionPolicyRepository}.
 *
 * ── Odkud lhůta pochází (`origin`) ────────────────────────────────────────────
 * `ORIGIN_STATUTE`      číslo je v zákoně; `section` říká kde.
 * `ORIGIN_HOUSE_POLICY` číslo dodala APLIKACE, protože zákon pro tuhle skupinu
 *                       záznamů uschovávací lhůtu nemá. Není to zákonná lhůta
 *                       a katalog to tak nesmí tvrdit — jinak by se v UI i
 *                       v auditní stopě vydával za právo něco, co v žádné
 *                       sbírce není.
 * `ORIGIN_NONE`         žádné číslo. `retention_years` je `null` a kategorie se
 *                       NIKDY nenavrhne k výmazu.
 *
 * ── Stav doloženosti (`source_status`) ────────────────────────────────────────
 * `STATUTE_VERIFIED`     doslovné znění účinného ustanovení; u čísel, která se
 *                        měnila, navíc doslovný text novely (pole `amendment`).
 * `STATUTE_SILENT`       NEGATIVNÍ důkaz: fulltext předpisu uschovávací lhůtu
 *                        neobsahuje. Není to „nenašli jsme" — je to doložené
 *                        „není tam".
 * `EXTERNAL_UNVERIFIED`  lhůta i zákon známé, doklad chybí.
 * `UNDETERMINED`         lhůta se nehledala nebo se nedohledala. Rezervováno pro
 *                        nově přidané kategorie; výslovné odmítnutí odhadu,
 *                        protože odhadnutá lhůta maže cizí data.
 *
 * ── Ověření z 15. 8. 2026 ─────────────────────────────────────────────────────
 * Katalog vznikl s čísly z odborné praxe, protože primární zdroj nebyl dostupný.
 * Ověření proběhlo proti doslovnému znění předpisů a je zapsané v
 * `private/RETENCNI-LHUTY-OVERENI.md` (adresář `private/` je mimo git, doklad
 * tedy není součástí repozitáře — proto `STATUTE_VERIFIED`, ne `repo_verified`).
 * Co ověření změnilo:
 *   • mzdové listy a záznamy pro důchodové pojištění: 30 → **45** let. Číslo 30
 *     přestalo platit 1. 1. 2023 (zákon č. 455/2022 Sb.); katalog by mazal
 *     o patnáct let dřív, než smí. To je nejzávažnější nález celého ověření.
 *   • pojistné na SZ: hodnota 10 sedí, ale pramen je § 22c zák. č. 589/1992 Sb.,
 *     ne § 35a odst. 4 zák. č. 582/1991 Sb. — ten o pojistném vůbec nemluví.
 *   • zdravotní pojištění: v zák. č. 592/1992 Sb. žádná uschovávací lhůta není.
 *     Deset let zůstává, ale jako DODANÁ POLITIKA (`ORIGIN_HOUSE_POLICY`).
 *     Zadavatel to v 8/2026 potvrdil — je to rozhodnutí, ne otevřená otázka.
 *   • evidence pracovní doby: lhůta EXISTUJE (§ 96 věta druhá zák. č. 187/2006
 *     Sb.), takže `UNDETERMINED` byl věcně nesprávný stav.
 *
 * ── Doplnění z 17. 8. 2026: katalog musí pokrýt VŠECHNO, co blokuje mazání ────
 * Posudek retence se ptá jen na tabulky, které katalog jmenuje. Tabulka, která
 * v katalogu není, ale blokuje smazání osoby (`BLOCKERS` / `GUARD_ONLY` v
 * {@see \MyInvoice\Repository\Payroll\PayrollEmployeeDeletionRepository} a
 * {@see \MyInvoice\Repository\Payroll\PayrollEmploymentDeletionRepository}),
 * vyrobí dvě protichůdné a obě nepravdivé odpovědi: posudek řekne, že osobu nic
 * nedrží, a samotné smazání pak selže na stráži. Osoba je držená napořád
 * a žádná lhůta ji nikdy neuvolní. Šestnáct takových tabulek se doplnilo
 * 17. 8. 2026; nově to hlídá
 * {@see \MyInvoice\Tests\Architecture\PayrollRetentionBlockerCoverageTest}.
 *
 * Přibyly dvě kategorie s vlastním, doloženým pramenem:
 *   • doklady o druhu, vzniku a skončení pracovního vztahu — § 96 věta druhá
 *     zák. č. 187/2006 Sb. (10 let); jiný předmět než evidence docházky, i když
 *     lhůtu mají společnou;
 *   • povinný příspěvek na produkty spoření na stáří — § 7 odst. 2 věta první
 *     zák. č. 324/2025 Sb. (10 let), účinný od 1. 1. 2026.
 *
 * ── Co katalog VĚDOMĚ nemodeluje ──────────────────────────────────────────────
 * 1. Zkrácenou lhůtu (10 let místo 45) pro poživatele důchodu — druhou část
 *    § 35a odst. 4 písm. c) a samostatnou lhůtu v písm. b) zák. č. 582/1991 Sb.
 *    Není to nedodělek implementace, ale MEZERA V ZÁKONĚ: evidenční povinnost
 *    podle § 37 odst. 1 písm. g) téhož zákona je UŽŠÍ než okruh, na který
 *    zkrácená lhůta dopadá — míří jen na předčasný starobní důchod podle § 31
 *    zákona o důchodovém pojištění a na důchod se sníženým důchodovým věkem.
 *    Poživatel řádného starobního důchodu se do evidenční povinnosti nevejde,
 *    takže zaměstnavatel z vlastní zákonné evidence obecně nezjistí, na koho
 *    zkrácená lhůta dopadá. Lepší kód to nespraví. Uplatnit 10 let místo 45 na
 *    základě příznaku, který zákon nenutí vést v potřebném rozsahu, by mazalo
 *    o 35 let dřív, než smí — proto se modeluje jen delší, bezpečná varianta.
 * 2. Lhůtu pro stanovení daně (§ 148 daňového řádu). Je to lhůta pro správce daně,
 *    ne uchovávací povinnost zaměstnavatele; míchat je dohromady by vyrobilo
 *    pravidlo, které v zákoně není.
 * 3. Druhou variantu lhůty u stejnopisů ELDP (běh od roku VYHOTOVENÍ). Katalog ji
 *    nese jako `alternative_basis`, ale posudek počítá nad ROKEM POSLEDNÍ STOPY
 *    osoby, ne nad rokem vyhotovení jednotlivého listu — viz poznámka u kategorie.
 * 4. Delší, 45letou variantu u dokladů o vzniku a skončení pracovního vztahu,
 *    kterou by dovolila fikce v § 35a odst. 4 zák. č. 582/1991 Sb. Zvážená
 *    a zadavatelem v 8/2026 zamítnutá ve prospěch doloženého minima — podrobně
 *    u kategorie `employment_relation_documents`.
 *
 * ── Účetní lhůtu si katalog NEDRŽÍ ────────────────────────────────────────────
 * Kategorie `accounting_records` nemá vlastní číslo. Lhůtu si bere z
 * {@see RetentionPolicy}, která je v aplikaci zdrojem pravdy pro § 31 ZoÚ už od
 * účetní strany. Dvě čísla pro tutéž lhůtu jsou přesně ta třída chyby, před
 * kterou varuje AGENTS.md: novela by opravila jedno a druhé by tiše mazalo dál.
 * Shodu hlídá test {@see \MyInvoice\Tests\Unit\Payroll\PayrollRetentionCatalogTest}.
 *
 * Dřívější „otevřené riziko" u rekodifikace účetnictví je uzavřené: zák.
 * č. 563/1991 Sb. je k 15. 8. 2026 v účinném znění s platností 1. 1. 2026 —
 * 9. 1. 2028, citace je tedy aktuální.
 */
final class PayrollRetentionCatalog
{
    public const PAYROLL_SHEET = 'payroll_sheet';
    public const PENSION_EVIDENCE = 'pension_evidence';
    public const PENSION_EVIDENCE_SHEETS = 'pension_evidence_sheets';
    public const SOCIAL_CONTRIBUTIONS = 'social_contributions';
    public const SOCIAL_DISCOUNT_DOCS = 'social_discount_documents';
    public const SICKNESS_INSURANCE = 'sickness_insurance';
    public const EMPLOYMENT_RELATION_DOCS = 'employment_relation_documents';
    public const HEALTH_INSURANCE = 'health_insurance';
    public const RISKY_SAVINGS = 'risky_savings';
    public const ACCOUNTING_RECORDS = 'accounting_records';
    public const WORKING_TIME = 'working_time';
    public const GARNISHMENT = 'garnishment';

    /** Doslovné znění účinného ustanovení, u změněných čísel i text novely. */
    public const STATUTE_VERIFIED = 'statute_verified';
    /** Negativní důkaz — fulltext předpisu uschovávací lhůtu neobsahuje. */
    public const STATUTE_SILENT = 'statute_silent';
    /** Lhůta i zákon známé, doklad není. */
    public const EXTERNAL_UNVERIFIED = 'external_unverified';
    /** Lhůta se nedohledala — kategorie se k výmazu nenavrhne. */
    public const UNDETERMINED = 'undetermined';

    /** Lhůtu stanoví zákon. */
    public const ORIGIN_STATUTE = 'statute';
    /** Lhůtu dodala aplikace tam, kde zákon mlčí — NENÍ to zákonná lhůta. */
    public const ORIGIN_HOUSE_POLICY = 'house_policy';
    /** Žádná lhůta — kategorie nikdy neexpiruje. */
    public const ORIGIN_NONE = 'none';

    /** Kalendářní roky následující po roce, kterého se záznam týká. */
    public const BASIS_CALENDAR_YEARS = 'calendar_years_after_record_year';
    /** Kalendářní roky po roce, ve kterém byl záznam VYHOTOVEN. */
    public const BASIS_CALENDAR_YEARS_AFTER_ISSUE = 'calendar_years_after_issue_year';
    /** Roky počínající koncem účetního období, kterého se záznam týká. */
    public const BASIS_ACCOUNTING_PERIOD = 'years_after_accounting_period_end';

    /**
     * Celé domény jako pole, ne jen jednotlivé konstanty.
     *
     * Katalog chodí po drátě do UI a každá hodnota tam musí mít větu — původ
     * lhůty se na obrazovce nesmí ukázat jako `house_policy`. Bez pojmenované
     * domény by se nová hodnota přidala do RULES a nikdo by nezjistil, že jí
     * chybí překlad ani že se s ní klientský union rozešel. Kontrakt hlídá
     * {@see \MyInvoice\Tests\Architecture\PayrollEnumContractTest}.
     */
    public const ORIGINS = [
        self::ORIGIN_STATUTE,
        self::ORIGIN_HOUSE_POLICY,
        self::ORIGIN_NONE,
    ];
    public const SOURCE_STATUSES = [
        self::STATUTE_VERIFIED,
        self::STATUTE_SILENT,
        self::EXTERNAL_UNVERIFIED,
        self::UNDETERMINED,
    ];
    public const BASES = [
        self::BASIS_CALENDAR_YEARS,
        self::BASIS_CALENDAR_YEARS_AFTER_ISSUE,
        self::BASIS_ACCOUNTING_PERIOD,
    ];

    /** Den, ke kterému byla znění předpisů ověřena. */
    public const VERIFIED_ON = '2026-08-15';
    /**
     * Den, ke kterému byla ověřena znění pro kategorie doplněné při uzavírání
     * mezery v pokrytí blokujících tabulek (§ 96 zák. č. 187/2006 Sb., § 7
     * zák. č. 324/2025 Sb., § 38j zák. č. 586/1992 Sb.).
     */
    public const VERIFIED_ON_COVERAGE = '2026-08-17';
    /** Kde je doklad o ověření (mimo git — viz docblock třídy). */
    public const VERIFICATION_REFERENCE = 'private/RETENCNI-LHUTY-OVERENI.md';

    private const ACT_SOCIAL_ORGANISATION = 'zákon č. 582/1991 Sb., o organizaci '
        . 'a provádění sociálního zabezpečení';
    private const ACT_SOCIAL_PREMIUMS = 'zákon č. 589/1992 Sb., o pojistném na '
        . 'sociální zabezpečení';
    private const ACT_SICKNESS = 'zákon č. 187/2006 Sb., o nemocenském pojištění';
    private const ACT_HEALTH_PREMIUMS = 'zákon č. 592/1992 Sb., o pojistném na '
        . 'veřejné zdravotní pojištění';
    private const ACT_ACCOUNTING = 'zákon č. 563/1991 Sb., o účetnictví';
    private const ACT_RISKY_SAVINGS = 'zákon č. 324/2025 Sb., o povinném příspěvku '
        . 'na produkty spoření na stáří';
    private const ACT_ENFORCEMENT = 'zákon č. 99/1963 Sb., občanský soudní řád, '
        . 'a zákon č. 120/2001 Sb., exekuční řád';

    /** Novela, která u mzdových listů zavedla dnešní číslo a dnešní písmeno. */
    private const AMENDMENT_PENSION_45 = 'číslo „30" nahrazeno číslem „45" zákonem '
        . 'č. 455/2022 Sb., čl. III bod 1, s účinností od 1. 1. 2023 a BEZ přechodného '
        . 'ustanovení k § 35a odst. 4; písmeno přeznačeno z d) na c) zákonem '
        . 'č. 360/2025 Sb. k 1. 1. 2026';

    /**
     * @var array<string,array{
     *   label:string,retention_years:?int,basis:string,alternative_basis:?string,
     *   origin:string,act:string,section:?string,amendment:?string,
     *   source_status:string,verified_on:?string,accounting_relevant:bool,
     *   closing_agenda:bool,employee_tables:list<string>,
     *   employment_tables:list<string>,note:string
     * }>
     */
    private const RULES = [
        self::PAYROLL_SHEET => [
            'label' => 'Mzdové listy',
            'retention_years' => 45,
            'basis' => self::BASIS_CALENDAR_YEARS,
            'alternative_basis' => null,
            'origin' => self::ORIGIN_STATUTE,
            'act' => self::ACT_SOCIAL_ORGANISATION,
            'section' => '§ 35a odst. 4 písm. c) zákona č. 582/1991 Sb.',
            'amendment' => self::AMENDMENT_PENSION_45,
            'source_status' => self::STATUTE_VERIFIED,
            'verified_on' => self::VERIFIED_ON,
            'accounting_relevant' => true,
            'closing_agenda' => false,
            'employee_tables' => [
                'payroll_monthly_records',
                'payroll_generated_documents',
                'payroll_document_batch_items',
                'payroll_annual_document_revisions',
                'payroll_inputs',
                'payroll_run_employments',
                'payroll_run_persons',
                'payroll_net_results',
                'payroll_annual_settlement_outcomes',
            ],
            'employment_tables' => [],
            'note' => 'Povinnost VÉST mzdový list plyne z § 38j zákona č. 586/1992 Sb.; '
                . 'lhůtu pro jeho uschování stanoví až předpis o sociálním zabezpečení, '
                . 'a to pro účely důchodového pojištění. Je to nejdelší lhůta v celé '
                . 'agendě a v praxi určuje, kdy vůbec smí osoba zmizet. Do 31. 12. 2022 '
                . 'to bylo 30 let — kdo drží starší katalog, maže o patnáct let dřív. '
                . 'Kategorie nese i tabulky, ze kterých se mzdový list SKLÁDÁ, ne jen '
                . 'vydaný doklad: § 38j odst. 2 písm. f) bod 1 až 7 žádá po mzdovém listu '
                . 'právě úhrn zúčtovaných mezd, základ, zálohu, slevu a bonus za každý '
                . 'kalendářní měsíc (mzdové vstupy, řádky běhu a výsledek čisté mzdy) '
                . 'a písm. h) navíc údaje o provedeném ročním zúčtování záloh. Kdyby tyhle '
                . 'tabulky zůstaly mimo katalog, osoba, po které zůstal jen spočítaný '
                . 'měsíc bez vydaného dokladu, by vycházela jako nezadržovaná — a smazání '
                . 'by přesto selhalo na stráži mazací rutiny.',
        ],
        self::PENSION_EVIDENCE_SHEETS => [
            'label' => 'Stejnopisy evidenčních listů důchodového pojištění',
            'retention_years' => 3,
            'basis' => self::BASIS_CALENDAR_YEARS,
            'alternative_basis' => self::BASIS_CALENDAR_YEARS_AFTER_ISSUE,
            'origin' => self::ORIGIN_STATUTE,
            'act' => self::ACT_SOCIAL_ORGANISATION,
            'section' => '§ 35a odst. 4 písm. a) zákona č. 582/1991 Sb., ve znění '
                . 'účinném do 31. 12. 2025',
            'amendment' => 'písmeno a) zrušeno zákonem č. 360/2025 Sb. (JMHZ) '
                . 'k 1. 1. 2026; lhůta dobíhá už jen podle čl. V bodu 2 písm. a) '
                . 'a bodu 8 přechodných ustanovení téhož zákona',
            'source_status' => self::STATUTE_VERIFIED,
            'verified_on' => self::VERIFIED_ON,
            'accounting_relevant' => false,
            'closing_agenda' => true,
            'employee_tables' => [
                'payroll_jmhz_eldp_evidence_snapshots',
                'payroll_eldp_statements',
            ],
            'employment_tables' => [
                'payroll_eldp_statement_claims',
                'payroll_jmhz_eldp_idempotency_claims',
            ],
            'note' => 'Idempotenční zámky nad evidenčním listem tu NEJSOU proto, že by měly '
                . 'vlastní zákonnou lhůtu — nemají žádnou, je to technický klíč bez věcného '
                . 'obsahu. Jsou tu proto, že vazba na vzniklý list je NULLOVATELNÁ: zámek, '
                . 'jehož list nikdy nevznikl, blokuje smazání osoby a přitom by ji žádná '
                . 'kategorie nedržela ani neuvolnila. Výjimkou „potomek rodičovské tabulky" '
                . 'je proto pokrýt nelze; drží lhůtu rodiče, aby vůbec někdy skončila. '
                . 'DOBÍHAJÍCÍ AGENDA: od 1. 1. 2026 se evidenční listy nevedou, '
                . 'kategorie tedy nikdy nedostane nová data (poslední přibudou za vztahy '
                . 'skončené před 1. 4. 2026). Zákon zná DVĚ báze: 3 roky po roce, kterého '
                . 'se list týká, a u listů vyhotovených později 3 roky po roce VYHOTOVENÍ. '
                . 'Posudek počítá nad rokem poslední stopy osoby, ne nad rokem vyhotovení '
                . 'jednotlivého listu — u opožděně vyhotoveného ELDP proto vychází kratší '
                . 'lhůta, než zákon žádá. Prakticky to nic neuvolní: osobu drží mzdový '
                . 'list na 45 let, a tři roky vedle něj nikdy nerozhodují.',
        ],
        self::PENSION_EVIDENCE => [
            'label' => 'Záznamy pro účely důchodového pojištění',
            'retention_years' => 45,
            'basis' => self::BASIS_CALENDAR_YEARS,
            'alternative_basis' => null,
            'origin' => self::ORIGIN_STATUTE,
            'act' => self::ACT_SOCIAL_ORGANISATION,
            'section' => '§ 35a odst. 4 písm. c) zákona č. 582/1991 Sb.',
            'amendment' => self::AMENDMENT_PENSION_45,
            'source_status' => self::STATUTE_VERIFIED,
            'verified_on' => self::VERIFIED_ON,
            'accounting_relevant' => false,
            'closing_agenda' => false,
            'employee_tables' => [
                'payroll_jmhz_ordinary_evidence_snapshots',
                'payroll_jmhz_ordinary_evidence_idempotency_claims',
                'payroll_person_social_jurisdictions',
                'payroll_person_external_ids',
                'payroll_employment_external_ids',
            ],
            'employment_tables' => [],
            'note' => 'Jde o TUTÉŽ větu jako u mzdových listů („mzdové listy nebo účetní '
                . 'záznamy o údajích potřebných pro účely důchodového pojištění"), takže '
                . 'sdílejí i číslo: 45 kalendářních roků. Identifikátor pracovněprávního '
                . 'vztahu přidělený ČSSZ (`payroll_employment_external_ids`) je tu ze '
                . 'stejného důvodu jako jeho protějšek na osobě: bez něj se údaj v evidenci '
                . 'podle § 37 odst. 1 zákona č. 582/1991 Sb. nedá k ničemu přiřadit. '
                . 'Idempotenční zámek nad evidencí JMHZ vlastní lhůtu nemá, ale vazba na '
                . 'vzniklý snapshot je nullovatelná — viz tatáž úvaha u stejnopisů '
                . 'evidenčních listů.',
        ],
        self::SOCIAL_CONTRIBUTIONS => [
            'label' => 'Záznamy pro stanovení a odvod pojistného na sociální zabezpečení',
            'retention_years' => 10,
            'basis' => self::BASIS_CALENDAR_YEARS,
            'alternative_basis' => null,
            'origin' => self::ORIGIN_STATUTE,
            'act' => self::ACT_SOCIAL_PREMIUMS,
            'section' => '§ 22c věta první zákona č. 589/1992 Sb.',
            'amendment' => null,
            'source_status' => self::STATUTE_VERIFIED,
            'verified_on' => self::VERIFIED_ON,
            'accounting_relevant' => true,
            'closing_agenda' => false,
            'employee_tables' => [
                'payroll_statutory_person_results',
                'payroll_statutory_accumulator_entries',
                'payroll_statutory_accumulator_openings',
            ],
            'employment_tables' => [],
            'note' => 'Pramen je zákon o POJISTNÉM (589/1992), ne zákon o organizaci '
                . 'sociálního zabezpečení (582/1991) — ten o pojistném vůbec nemluví '
                . 'a katalog ho tu do 15. 8. 2026 citoval mylně. Kratší lhůta než '
                . 'u důchodových údajů: týká se odvodu pojistného, ne nároku na důchod. '
                . 'Sama nic neuvolní, viz maximum přes kategorie.',
        ],
        self::SOCIAL_DISCOUNT_DOCS => [
            'label' => 'Doklady ke slevám na pojistném na sociální zabezpečení',
            'retention_years' => 10,
            'basis' => self::BASIS_CALENDAR_YEARS,
            'alternative_basis' => null,
            'origin' => self::ORIGIN_STATUTE,
            'act' => self::ACT_SOCIAL_PREMIUMS,
            'section' => '§ 22c věta druhá zákona č. 589/1992 Sb.',
            'amendment' => null,
            'source_status' => self::STATUTE_VERIFIED,
            'verified_on' => self::VERIFIED_ON,
            'accounting_relevant' => true,
            'closing_agenda' => false,
            'employee_tables' => ['payroll_person_social_discount_claims'],
            // Oznámený záměr uplatňovat slevu (OZUSPOJ, § 23e) je doklad ke
            // stanovení slevy stejně jako podklady podle § 23d odst. 1 a váže
            // se na pracovní vztah, ze kterého se sleva uplatňuje.
            'employment_tables' => ['payroll_discount_intents'],
            'note' => 'Věta druhá § 22c má vlastní předmět — doklady potřebné ke stanovení '
                . 'SLEVY na pojistném (u nás nárok pracujícího důchodce a další režimy '
                . 'podle § 7a zákona č. 589/1992 Sb.). Doklad ke slevě není záznamem pro '
                . 'účely důchodového pojištění, proto má vlastní kategorii a ne 45 let: '
                . 'lhůta se má vázat na ustanovení, které ji skutečně stanoví, aby ji '
                . 'příští novela našla. V praxi osobu stejně drží mzdový list.',
        ],
        self::SICKNESS_INSURANCE => [
            'label' => 'Záznamy pro nemocenské pojištění',
            'retention_years' => 10,
            'basis' => self::BASIS_CALENDAR_YEARS,
            'alternative_basis' => null,
            'origin' => self::ORIGIN_STATUTE,
            'act' => self::ACT_SICKNESS,
            'section' => '§ 96 věta první zákona č. 187/2006 Sb.',
            'amendment' => null,
            'source_status' => self::STATUTE_VERIFIED,
            'verified_on' => self::VERIFIED_ON,
            'accounting_relevant' => false,
            'closing_agenda' => false,
            'employee_tables' => ['payroll_statutory_obligation_evidence'],
            'employment_tables' => ['payroll_absences'],
            'note' => 'Věta první § 96 ukládá uschovat záznamy o skutečnostech podle § 95 '
                . 'po dobu 10 kalendářních roků následujících po roce, kterého se týkají, '
                . 'nestanoví-li zvláštní předpis pro záznamy s povahou účetního záznamu '
                . 'dobu DELŠÍ. Nositelem evidence je záznam o nepřítomnosti — '
                . '`payroll_sickness_events` je jen dopočet nad ním a vlastní vazbu na '
                . 'osobu nemá, takže by se v sondě choval jako tabulka bez vlastníka.',
        ],
        self::EMPLOYMENT_RELATION_DOCS => [
            'label' => 'Doklady o druhu, vzniku a skončení pracovního vztahu',
            'retention_years' => 10,
            'basis' => self::BASIS_CALENDAR_YEARS,
            'alternative_basis' => null,
            'origin' => self::ORIGIN_STATUTE,
            'act' => self::ACT_SICKNESS,
            'section' => '§ 96 věta druhá zákona č. 187/2006 Sb.',
            'amendment' => null,
            'source_status' => self::STATUTE_VERIFIED,
            'verified_on' => self::VERIFIED_ON_COVERAGE,
            'accounting_relevant' => false,
            'closing_agenda' => false,
            'employee_tables' => [
                'payroll_registration_identity_snapshots',
                'payroll_employment_exit_revisions',
                'payroll_identity_resolution_tasks',
            ],
            'employment_tables' => [],
            'note' => 'Vlastní kategorie, ne přílepek k evidenci pracovní doby: § 96 věta '
                . 'druhá zákona č. 187/2006 Sb. prohlašuje za záznamy podle § 95 DVĚ různé '
                . 'skupiny — „doklady o druhu, vzniku a skončení pracovního vztahu" '
                . 'a „záznamy o evidenci docházky do práce". Lhůta je u obou stejná (10 '
                . 'kalendářních roků podle věty první), ale předmět jiný, a citace se má '
                . 'vázat na to, co skutečně pokrývá. Vznik vztahu drží zašifrovaný snapshot '
                . 'identity odeslané do registračních agend ČSSZ, skončení výstupní doklad '
                . '(zápočtový list, potvrzení o průměrném výdělku). Úkol na dořešení '
                . 'identity sám dokladem není — je to auditní stopa k tomu, komu byl '
                . 'registrační doklad přiřazen; zároveň mazání blokuje, takže mimo katalog '
                . 'by osobu držel bez konce. Nesouvisí to s § 313 zákoníku práce, ten '
                . 'ukládá potvrzení VYDAT, ne uschovat. '
                . 'DELŠÍ VARIANTA BYLA ZVÁŽENÁ A ZAMÍTNUTÁ: fikce v § 35a odst. 4 zákona '
                . 'č. 582/1991 Sb. jmenuje mezi záznamy pro důchodové pojištění tytéž '
                . 'doklady o vzniku a skončení pracovního vztahu, což by lhůtu zvedlo '
                . 'z 10 na 45 let. Doslovné znění ale neurčuje, ke kterému písmenu se ta '
                . 'věta váže, takže delší lhůta by stála na výkladu, ne na textu. '
                . 'Zadavatel v 8/2026 rozhodl pro DOLOŽENÉ MINIMUM: kategorie zůstává na '
                . '10 letech podle § 96 věty druhé zákona č. 187/2006 Sb. Delší lhůtu si '
                . 'zaměstnavatel může nastavit odchylkou v `payroll_retention_policies`, '
                . 'protože ty přebíjejí katalog směrem NAHORU.',
        ],
        self::HEALTH_INSURANCE => [
            'label' => 'Záznamy pro zdravotní pojištění',
            'retention_years' => 10,
            'basis' => self::BASIS_CALENDAR_YEARS,
            'alternative_basis' => null,
            'origin' => self::ORIGIN_HOUSE_POLICY,
            'act' => self::ACT_HEALTH_PREMIUMS,
            'section' => null,
            'amendment' => null,
            'source_status' => self::STATUTE_SILENT,
            'verified_on' => self::VERIFIED_ON,
            'accounting_relevant' => true,
            'closing_agenda' => false,
            'employee_tables' => [
                'payroll_person_health_coverage_history',
                'payroll_person_health_month_evidence',
                'payroll_person_health_minimum_reductions',
                'payroll_person_health_other_employer_bases',
            ],
            'employment_tables' => [],
            'note' => 'JEDINÁ DODANÁ LHŮTA V KATALOGU, a zadavatel ji v 8/2026 potvrdil — '
                . 'nečeká se na rozhodnutí, rozhodnutí padlo. Fulltext zákona č. 592/1992 Sb. '
                . 'neobsahuje slovo „uschov" ani jednou; § 25 odst. 5 ukládá vést '
                . 'průkaznou evidenci o platbách pojistného, ale ŽÁDNOU lhůtu nestanoví. '
                . 'Deset let se v praxi opisuje z § 16 odst. 1 (předepsání dlužného '
                . 'pojistného) — to ale nikdy nebyla uschovávací lhůta a od 1. 1. 2026 '
                . 'tam stojí 6 let, ne 10. Držet 10 let je bezpečné a odpovídá zákonnému '
                . 'minimu, které na tytéž řádky dopadá odjinud (§ 22c zákona '
                . 'č. 589/1992 Sb. pro údaje k odvodu pojistného, § 31 odst. 2 písm. b) '
                . 'zákona č. 563/1991 Sb. pro účetní záznamy), ale jako ZÁKONNOU lhůtu '
                . 'zdravotního pojištění ji katalog vydávat nesmí.',
        ],
        self::RISKY_SAVINGS => [
            'label' => 'Záznamy o povinném příspěvku na produkty spoření na stáří',
            'retention_years' => 10,
            'basis' => self::BASIS_CALENDAR_YEARS,
            'alternative_basis' => null,
            'origin' => self::ORIGIN_STATUTE,
            'act' => self::ACT_RISKY_SAVINGS,
            'section' => '§ 7 odst. 2 věta první zákona č. 324/2025 Sb.',
            'amendment' => null,
            'source_status' => self::STATUTE_VERIFIED,
            'verified_on' => self::VERIFIED_ON_COVERAGE,
            'accounting_relevant' => true,
            'closing_agenda' => false,
            'employee_tables' => [],
            'employment_tables' => ['payroll_risky_savings_contributions'],
            'note' => 'Zákon má VLASTNÍ uschovávací lhůtu a vlastní evidenční povinnost, '
                . 'takže se pod pojistné ani pod účetnictví schovat nedá: § 7 odst. 1 žádá '
                . 'vést seznam zaměstnanců v rizikové práci, počet odpracovaných '
                . 'kvalifikujících směn v rozhodném období a výši zaplacených příspěvků — '
                . 'tedy přesně to, co tabulka drží — a § 7 odst. 2 věta první ukládá '
                . 'uschovat je „po dobu 10 kalendářních roků následujících po roce, kterého '
                . 'se týkají". Neuschování je podle § 8 odst. 1 písm. e) přestupek. '
                . 'Povinnost platí od 1. 1. 2026, kdy zákon nabyl účinnosti.',
        ],
        self::ACCOUNTING_RECORDS => [
            // `null` tady NEZNAMENÁ neurčenou lhůtu — accounting_records si ji bere
            // z RetentionPolicy (viz rule()). Původ je proto ORIGIN_STATUTE.
            'label' => 'Účetní doklady a účetní záznamy',
            'retention_years' => null,
            'basis' => self::BASIS_ACCOUNTING_PERIOD,
            'alternative_basis' => null,
            'origin' => self::ORIGIN_STATUTE,
            'act' => self::ACT_ACCOUNTING,
            'section' => '§ 31 odst. 2 písm. b) zákona č. 563/1991 Sb.',
            'amendment' => null,
            'source_status' => self::STATUTE_VERIFIED,
            'verified_on' => self::VERIFIED_ON,
            'accounting_relevant' => true,
            'closing_agenda' => false,
            'employee_tables' => [
                'payroll_payment_liabilities',
                'payroll_payout_allocations',
                'payroll_deduction_ledger',
                'payroll_benefit_accumulators',
                'payroll_business_trips',
            ],
            'employment_tables' => [],
            'note' => 'Cestovní příkaz a jeho vyúčtování je účetní doklad, ne mzdový záznam: '
                . 'náhrada do limitu není podle § 6 odst. 7 zákona č. 586/1992 Sb. předmětem '
                . 'daně, takže se do úhrnu zúčtovaných mezd ve mzdovém listě nedostane. Část '
                . 'nad limit ano — ta ale do mzdy vstupuje mzdovým vstupem, a ten už drží '
                . 'kategorie mzdových listů na 45 let. Krátká účetní lhůta tedy nic '
                . 'daňového neuvolní dřív. '
                . 'Účetní doklady, knihy a přehledy 5 let počínajících koncem účetního '
                . 'období (písm. b); účetní závěrka a výroční zpráva 10 let (písm. a) — '
                . 'ty ale nejsou vázané na osobu, takže je tenhle katalog neřeší. Podle '
                . '§ 32 odst. 2 téhož zákona může být účetním záznamem i mzdový list; '
                . 'souběh řeší § 35a odst. 4 věta poslední zákona č. 582/1991 Sb. tak, že '
                . 'platí DELŠÍ lhůta — s čímž je „maximum přes kategorie" v souladu. '
                . 'Účetní záznam se NIKDY neruší řádkově: osobní údaj z něj zmizí '
                . 'anonymizací, částka zůstane.',
        ],
        self::WORKING_TIME => [
            'label' => 'Evidence pracovní doby',
            'retention_years' => 10,
            'basis' => self::BASIS_CALENDAR_YEARS,
            'alternative_basis' => null,
            'origin' => self::ORIGIN_STATUTE,
            'act' => self::ACT_SICKNESS,
            'section' => '§ 96 věta druhá zákona č. 187/2006 Sb.',
            'amendment' => null,
            'source_status' => self::STATUTE_VERIFIED,
            'verified_on' => self::VERIFIED_ON,
            'accounting_relevant' => false,
            'closing_agenda' => false,
            'employee_tables' => [],
            'employment_tables' => [
                'payroll_time_entries',
                'payroll_absences',
                'payroll_leave_ledger',
                'payroll_overtime_consents',
                'payroll_jmhz_work_month_revisions',
            ],
            'note' => 'Zmrazená revize pracovního měsíce pro JMHZ patří sem, a ne pod '
                . 'evidenci pro důchodové pojištění: nese fond pracovní doby, evidenční dny '
                . 'a odpracované hodiny, tedy docházku, ne vyměřovací základ. '
                . 'Lhůtu nestanoví zákoník práce — § 96 ZP evidenci jen PŘIKAZUJE '
                . 'VÉST — ale stanoví ji předpis o nemocenském pojištění: § 96 věta druhá '
                . 'zákona č. 187/2006 Sb. prohlašuje záznamy o evidenci docházky do práce '
                . 'včetně pracovního volna bez náhrady příjmu za záznamy podle § 95, '
                . 'a ty se uschovávají 10 kalendářních roků následujících po roce, kterého '
                . 'se týkají. Není to odvození z promlčecí doby, je to text zákona; do '
                . '15. 8. 2026 katalog tvrdil opak a osobu s docházkou proto k výmazu '
                . 'nenavrhl NIKDY. NEJEDNOZNAČNÉ zůstává, zda se na evidenci pracovní doby '
                . 'neváže i fikce v § 35a odst. 4 zákona č. 582/1991 Sb. (pak by šlo až '
                . 'o 45 let) — doslovné znění to neurčuje, proto katalog drží doložené '
                . 'minimum 10 let. Prodloužit ho může tenant vlastní politikou.',
        ],
        self::GARNISHMENT => [
            'label' => 'Doklady k exekučním srážkám',
            'retention_years' => null,
            'basis' => self::BASIS_CALENDAR_YEARS,
            'alternative_basis' => null,
            'origin' => self::ORIGIN_NONE,
            'act' => self::ACT_ENFORCEMENT,
            'section' => null,
            'amendment' => null,
            'source_status' => self::STATUTE_SILENT,
            'verified_on' => self::VERIFIED_ON,
            'accounting_relevant' => true,
            'closing_agenda' => false,
            'employee_tables' => [
                'payroll_enforcement_cases',
                'payroll_enforcement_dependants',
                'payroll_enforcement_month_results',
                'payroll_enforcement_person_month_evidence',
                'payroll_insolvency_payment_instructions',
                'payroll_enforcement_xmlzam_requests',
                'payroll_deduction_agreements',
            ],
            'employment_tables' => [],
            'note' => 'Ověřeno NEGATIVNĚ v obou předpisech: v občanském soudním řádu se '
                . 'uschovávání týká jen prodeje nevyzvednutých movitých věcí, v exekučním '
                . 'řádu je uschovávací povinnost uložena EXEKUTOROVI, ne plátci mzdy. '
                . 'Sražené částky samotné kryje hned trojí lhůta (§ 35a odst. 4 písm. c) '
                . 'zákona č. 582/1991 Sb., § 22c zákona č. 589/1992 Sb. a § 31 odst. 2 '
                . 'písm. b) zákona č. 563/1991 Sb.); spis k exekuci ale vlastní lhůtu '
                . 'nemá a zůstává neurčený právem. Odpovědnost plátce mzdy podle § 292 '
                . 'o. s. ř. a její promlčení jsou úvaha, ne uschovávací povinnost — '
                . 'lhůtu si tu smí dodat jen tenant vlastní politikou.',
        ],
    ];

    /**
     * Katalog musí jít ZAVOLAT (AGENTS.md) — schématový i architektonický test
     * proti němu ověřují, že každá uvedená tabulka existuje a že žádná kategorie
     * nezůstala bez citace.
     *
     * @return list<string>
     */
    public static function categories(): array
    {
        return array_keys(self::RULES);
    }

    public static function has(string $category): bool
    {
        return isset(self::RULES[$category]);
    }

    public static function rule(string $category): PayrollRetentionRule
    {
        $rule = self::RULES[$category] ?? null;
        if ($rule === null) {
            throw new \InvalidArgumentException(
                'Neznámá retenční kategorie mzdové agendy.',
            );
        }

        return new PayrollRetentionRule(
            $category,
            $rule['label'],
            $category === self::ACCOUNTING_RECORDS
                ? RetentionPolicy::retentionYears(RetentionPolicy::ACCOUNTING_RECORDS)
                : $rule['retention_years'],
            $rule['basis'],
            $rule['alternative_basis'],
            $rule['origin'],
            $rule['act'],
            $rule['section'],
            $rule['amendment'],
            $rule['source_status'],
            $rule['verified_on'],
            $rule['accounting_relevant'],
            $rule['closing_agenda'],
            $rule['employee_tables'],
            $rule['employment_tables'],
            $rule['note'],
        );
    }

    /** @return list<PayrollRetentionRule> */
    public static function rules(): array
    {
        return array_map(
            static fn (string $category): PayrollRetentionRule => self::rule($category),
            self::categories(),
        );
    }

    /**
     * Všechny tabulky, které katalog sleduje — sonda pro schématový test, aby
     * nová tabulka s osobními údaji nezůstala mimo retenci.
     *
     * @return list<string>
     */
    public static function trackedTables(): array
    {
        $tables = [];
        foreach (self::RULES as $rule) {
            foreach ($rule['employee_tables'] as $table) {
                $tables[] = $table;
            }
            foreach ($rule['employment_tables'] as $table) {
                $tables[] = $table;
            }
        }

        return array_values(array_unique($tables));
    }
}
