<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration\Change;

/**
 * Které údaje pracovního vztahu se do registru pojištěnců HLÁSÍ, když se změní.
 *
 * ## Proč to musí být katalog, a ne „porovnej všechno"
 *
 * Registrační akce A3 má lhůtu osm dnů ode dne, kdy se zaměstnavatel o změně
 * dozvěděl (§ 19 odst. 5 zákona č. 323/2025 Sb.). Kdyby detekce porovnávala
 * celý pracovní vztah, hlásila by i změnu úvazku nebo mzdy — a to jsou
 * MĚSÍČNÍ atributy hlášení (příloha č. 1 část C nař. vl. 417/2025 Sb.), které
 * se projeví samy v nejbližším měsíčním hlášení a osmidenní lhůtu nespouštějí.
 * Planý poplach u položky, která žádnou lhůtu nemá, je horší než žádný: účetní
 * si na něj zvykne a přestane číst i ten pravý.
 *
 * Katalog je proto UZAVŘENÝ SEZNAM cest odvozený z přílohy č. 4 části G
 * nař. vl. 417/2025 Sb. Cesta, která tu není, se neporovnává vůbec, a
 * {@see PayrollRegistrationChangeDetector} navíc skončí chybou, kdyby se do
 * porovnávaného průmětu dostala cesta, kterou katalog nezná. Jinými slovy:
 * přidat nový hlásitelný údaj jde jen vědomě, tady, s odkazem na pramen.
 *
 * ## Co sem vědomě NEPATŘÍ
 *
 * {@see self::MONTHLY_REPORT_ONLY} vyjmenovává měsíční atributy, které se do
 * průmětu nesmí dostat. Není to dokumentace pro lidi — na tenhle seznam se
 * ptá {@see PayrollRegistrationReportableProfileBuilder} i architektonický
 * test, takže „změna úvazku nevyrobí událost" je vlastnost, ne shoda náhod.
 *
 * ## Údaje, které jsou hlásitelné, ale tenhle core je detekovat neumí
 *
 * {@see self::WITHOUT_BASELINE} drží položky z části G, ke kterým v aplikaci
 * NEEXISTUJE zmrazený stav „co jsme o nich naposledy nahlásili", takže by
 * porovnání buď mlčelo, nebo si výchozí hodnotu vymyslelo. Mlčet potichu je
 * horší než mlčet nahlas: seznam se vrací i do API, aby bylo poznat, kde
 * detekce nekryje záda a povinnost zůstává na člověku.
 */
final class PayrollRegistrationReportableCatalog
{
    /** Změna údaje, která se hlásí akcí A3. */
    public const ACTION_CHANGE = 3;

    /**
     * Vznik příslušnosti k cizím právním předpisům. Část G sice příznak
     * příslušnosti vede, ale jeho VZNIK a SKONČENÍ jsou samostatné akce
     * A6 a A7 — poslat je jako A3 by bylo podání ve špatné akci.
     */
    public const ACTION_FOREIGN_LEGISLATION_START = 6;

    /** Skončení příslušnosti k cizím právním předpisům. */
    public const ACTION_FOREIGN_LEGISLATION_END = 7;

    public const GROUP_IDENTITY = 'identity';
    public const GROUP_IDENTIFIERS = 'identifiers';
    public const GROUP_PROOF_IDENTITY = 'proof_identity';
    public const GROUP_TAX_RESIDENCY = 'tax_residency';
    public const GROUP_PERMANENT_ADDRESS = 'permanent_address';
    public const GROUP_CONTACT_ADDRESS = 'contact_address';
    public const GROUP_CZECH_RESIDENCE_ADDRESS = 'czech_residence_address';
    public const GROUP_FOREIGN_RESIDENCE_ADDRESS = 'foreign_residence_address';
    public const GROUP_HEALTH = 'health';
    public const GROUP_EDUCATION = 'education';
    public const GROUP_HEALTH_INSURANCE = 'health_insurance';
    public const GROUP_FOREIGN_WORKER = 'foreign_worker';
    public const GROUP_FOREIGN_LEGISLATION = 'foreign_legislation';
    public const GROUP_PENSION = 'pension';
    public const GROUP_EMPLOYMENT = 'employment';

    /** Pole adresního bloku podle části G. */
    private const ADDRESS_FIELDS = [
        'ruian_point',
        'street',
        'house_number',
        'orientation_number',
        'city',
        'postal_code',
        'country_code',
    ];

    /**
     * Hlásitelné údaje mimo adresní bloky.
     *
     * `sensitive` znamená „hodnota se nesmí objevit v odpovědi API": rodné
     * číslo, EČP, VČP, daňový identifikátor a číslo dokladu se do návrhu
     * vracejí jen jako „změnilo se", nikdy jako `z → na`.
     *
     * @var array<string,array{group:string,action:int,sensitive:bool}>
     */
    private const SCALAR_FIELDS = [
        // Identita a doklady.
        'identity.first_name' => [self::GROUP_IDENTITY, 3, false],
        'identity.last_name' => [self::GROUP_IDENTITY, 3, false],
        'identity.title_prefix' => [self::GROUP_IDENTITY, 3, false],
        'identity.title_suffix' => [self::GROUP_IDENTITY, 3, false],
        'identity.birth_surname' => [self::GROUP_IDENTITY, 3, false],
        'identity.birth_date' => [self::GROUP_IDENTITY, 3, false],
        'identity.sex' => [self::GROUP_IDENTITY, 3, false],
        'identity.citizenship_country_code' => [self::GROUP_IDENTITY, 3, false],

        'identifiers.birth_number' => [self::GROUP_IDENTIFIERS, 3, true],
        'identifiers.ecp' => [self::GROUP_IDENTIFIERS, 3, true],
        'identifiers.vcp' => [self::GROUP_IDENTIFIERS, 3, true],

        'proof_identity.type_code' => [self::GROUP_PROOF_IDENTITY, 3, false],
        'proof_identity.number' => [self::GROUP_PROOF_IDENTITY, 3, true],
        'proof_identity.foreign_issuer' => [self::GROUP_PROOF_IDENTITY, 3, false],
        'proof_identity.country_code' => [self::GROUP_PROOF_IDENTITY, 3, false],

        // Daňová rezidence.
        'tax_residency.country_code' => [self::GROUP_TAX_RESIDENCY, 3, false],
        'tax_residency.identifier_type' => [self::GROUP_TAX_RESIDENCY, 3, false],
        'tax_residency.identifier' => [self::GROUP_TAX_RESIDENCY, 3, true],

        // Zdravotní stav a vzdělání.
        'facts.disability_card' => [self::GROUP_HEALTH, 3, false],
        'facts.health_restrictions' => [self::GROUP_HEALTH, 3, false],
        'facts.highest_education_code' => [self::GROUP_EDUCATION, 3, false],

        // Zdravotní pojišťovna. Tatáž změna zakládá i povinnost vůči
        // pojišťovnám podle § 10 odst. 1 písm. b) zákona č. 48/1997 Sb.;
        // řeší ji PayrollRegistrationChangeDetectionService, protože JMHZ
        // tuhle povinnost NENAHRAZUJE.
        'health_insurance_code' => [self::GROUP_HEALTH_INSURANCE, 3, false],

        // Cizinci a přístup na trh práce.
        'foreign_worker.free_access' => [self::GROUP_FOREIGN_WORKER, 3, false],
        'foreign_worker.free_access_reason_code' => [self::GROUP_FOREIGN_WORKER, 3, false],
        'foreign_worker.permit_type_code' => [self::GROUP_FOREIGN_WORKER, 3, false],
        'foreign_worker.issuing_labour_office_code' => [self::GROUP_FOREIGN_WORKER, 3, false],
        'foreign_worker.permit_identifier' => [self::GROUP_FOREIGN_WORKER, 3, false],
        'foreign_worker.permit_from' => [self::GROUP_FOREIGN_WORKER, 3, false],
        'foreign_worker.permit_to' => [self::GROUP_FOREIGN_WORKER, 3, false],

        // Příslušnost k cizím předpisům. Příznak = vznik/skončení → A6/A7,
        // sám kód státu při trvající příslušnosti = A3.
        'foreign_legislation.applies' => [self::GROUP_FOREIGN_LEGISLATION, 6, false],
        'foreign_legislation.country_code' => [self::GROUP_FOREIGN_LEGISLATION, 3, false],

        // Důchod.
        'pension.type_code' => [self::GROUP_PENSION, 3, false],
        'pension.received_from' => [self::GROUP_PENSION, 3, false],
        'pension.early_retirement' => [self::GROUP_PENSION, 3, false],
        'pension.reduced_retirement_age' => [self::GROUP_PENSION, 3, false],

        // Zaměstnání, místo výkonu práce, profese a režim.
        'employment.activity_code' => [self::GROUP_EMPLOYMENT, 3, false],
        'employment.relationship_detail_code' => [self::GROUP_EMPLOYMENT, 3, false],
        'employment.actual_start_on' => [self::GROUP_EMPLOYMENT, 3, false],
        'employment.contract_start_on' => [self::GROUP_EMPLOYMENT, 3, false],
        'employment.small_scale' => [self::GROUP_EMPLOYMENT, 3, false],
        'employment.employment_status_code' => [self::GROUP_EMPLOYMENT, 3, false],
        'employment.work_mode_code' => [self::GROUP_EMPLOYMENT, 3, false],
        'employment.continuous_operation' => [self::GROUP_EMPLOYMENT, 3, false],
        'employment.prevailing_workplace_code' => [self::GROUP_EMPLOYMENT, 3, false],
        'employment.expected_workplaces' => [self::GROUP_EMPLOYMENT, 3, false],
        'employment.contract_workplace' => [self::GROUP_EMPLOYMENT, 3, false],
        'employment.workplace_city' => [self::GROUP_EMPLOYMENT, 3, false],
        'employment.workplace_municipality_code' => [self::GROUP_EMPLOYMENT, 3, false],
        'employment.profession_code' => [self::GROUP_EMPLOYMENT, 3, false],
        'employment.required_education_code' => [self::GROUP_EMPLOYMENT, 3, false],
        'employment.position_name' => [self::GROUP_EMPLOYMENT, 3, false],
        'employment.leadership' => [self::GROUP_EMPLOYMENT, 3, false],
    ];

    /**
     * Adresní bloky části G. Čtyři, ne jeden: pobyt, doručovací, přechodný
     * pobyt v ČR a bydliště ve státě daňové rezidence. Každý má vlastní
     * skupinu, aby návrh uměl říct KTERÁ adresa se změnila.
     *
     * @var array<string,string>
     */
    private const ADDRESS_BLOCKS = [
        'permanent_address' => self::GROUP_PERMANENT_ADDRESS,
        'contact_address' => self::GROUP_CONTACT_ADDRESS,
        'czech_residence_address' => self::GROUP_CZECH_RESIDENCE_ADDRESS,
        'tax_residency.residence_address' => self::GROUP_FOREIGN_RESIDENCE_ADDRESS,
    ];

    /**
     * Měsíční atributy hlášení (příloha č. 1 část C nař. vl. 417/2025 Sb.).
     *
     * Změna žádného z nich NEZAKLÁDÁ akci A3 ani osmidenní lhůtu — projeví se
     * sama v nejbližším měsíčním hlášení. Seznam není jen komentář: průmět se
     * proti němu kontroluje, takže kdyby někdo do projekce přidal třeba
     * `terms.weekly_hours`, builder skončí chybou místo toho, aby začal
     * účetní hlásit změnu úvazku na ČSSZ do osmi dnů.
     *
     * @var list<string>
     */
    public const MONTHLY_REPORT_ONLY = [
        'terms.weekly_hours',
        'terms.stated_weekly_hours',
        'terms.agreed_weekly_hours',
        'terms.statutory_weekly_hours',
        'terms.monthly_gross_minor',
        'terms.hourly_rate_minor',
        'terms.wage_components',
        'terms.temporary_assignment',
        'attendance.days_in_evidence',
        'attendance.worked_hours',
        'attendance.overtime_hours',
        'attendance.unworked_hours',
        'income.gross_minor',
        'income.advances_minor',
        'income.reliefs_minor',
        'income.bonuses_minor',
        'income.withholding_tax_minor',
        'income.deductions_minor',
        'eldp.periods',
        'apz.contribution_minor',
    ];

    /**
     * Hlásitelné údaje z části G bez zmrazeného výchozího stavu v tomhle core.
     *
     * Klíč je cesta, hodnota důvod. Vrací se do API i do přehledu termínů,
     * aby účetní věděla, co si musí ohlídat sama.
     *
     * @var array<string,string>
     */
    public const WITHOUT_BASELINE = [
        'employer.variable_symbol' =>
            'Změna variabilního symbolu se v aplikaci podává samostatnou '
            . 'interakcí A5 (převod zaměstnavatele), ne akcí A3.',
        'employer.name' =>
            'Název zaměstnavatele nemá zmrazený registrační stav; mění se '
            . 'v profilu firmy a do registru jde přes evidenci zaměstnavatele.',
        'employment.id_ppv' =>
            'ID PPV přiděluje ČSSZ; jeho změnu nese samostatný tok přiřazení '
            . 'externího identifikátoru, ne porovnání profilu.',
        'foreign_insurance.carrier' =>
            'Nositel pojištění v cizině, cizozemské číslo pojištění a orgán '
            . 'nemocenského pojištění se evidují až v podání A6/A7, takže '
            . 'nemají registrační výchozí stav k porovnání.',
    ];

    /**
     * Všechny hlásitelné cesty.
     *
     * @return list<string>
     */
    public static function paths(): array
    {
        return array_keys(self::definitions());
    }

    public static function isReportable(string $path): bool
    {
        return array_key_exists($path, self::definitions());
    }

    /** @return array{group:string,action:int,sensitive:bool} */
    public static function definition(string $path): array
    {
        $definition = self::definitions()[$path] ?? null;
        if ($definition === null) {
            throw new \InvalidArgumentException(
                "Cesta {$path} není v katalogu hlásitelných údajů REGZEC.",
            );
        }

        return $definition;
    }

    /** Cesta patří mezi měsíční atributy, které A3 nespouštějí. */
    public static function isMonthlyReportOnly(string $path): bool
    {
        return in_array($path, self::MONTHLY_REPORT_ONLY, true);
    }

    /**
     * Cesty jednoho adresního bloku, v pořadí části G.
     *
     * @return list<string>
     */
    public static function addressPaths(string $block): array
    {
        if (!array_key_exists($block, self::ADDRESS_BLOCKS)) {
            throw new \InvalidArgumentException(
                "Adresní blok {$block} REGZEC nezná.",
            );
        }

        return array_map(
            static fn (string $field): string => "{$block}.{$field}",
            self::ADDRESS_FIELDS,
        );
    }

    /** @return list<string> */
    public static function addressBlocks(): array
    {
        return array_keys(self::ADDRESS_BLOCKS);
    }

    /** @return array<string,array{group:string,action:int,sensitive:bool}> */
    private static function definitions(): array
    {
        /** @var array<string,array{group:string,action:int,sensitive:bool}>|null $cache */
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $result = [];
        foreach (self::SCALAR_FIELDS as $path => [$group, $action, $sensitive]) {
            $result[$path] = [
                'group' => $group,
                'action' => $action,
                'sensitive' => $sensitive,
            ];
        }
        foreach (self::ADDRESS_BLOCKS as $block => $group) {
            foreach (self::ADDRESS_FIELDS as $field) {
                $result["{$block}.{$field}"] = [
                    'group' => $group,
                    'action' => self::ACTION_CHANGE,
                    'sensitive' => false,
                ];
            }
        }
        ksort($result, SORT_STRING);
        $cache = $result;

        return $cache;
    }
}
