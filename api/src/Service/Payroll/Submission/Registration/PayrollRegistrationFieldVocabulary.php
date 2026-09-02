<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

/**
 * Slovník registračního řetězce: technická cesta k poli → lidský název a MÍSTO,
 * kde se údaj zadává.
 *
 * Why: hlášky uměly jen technický název sloupce („nemá platné povinné pole
 * citizenship_country_code", „identifiers.vcp musí být neprázdný text").
 * Účetní z nich nepozná ani co to je, ani kam jít. Slovník je jeden pro celý
 * řetězec (A1, identita, události, XML, přenos), aby se hlášky nerozešly podle
 * toho, která třída je vypsala.
 *
 * ZÁLOŽKA PATŘÍ DO CESTY. Bez ní popis mířil na sekci, kterou jiná záložka
 * karty vůbec nevykresluje — účetní ji hledala a nenašla, protože stála na
 * „Kontaktech". Tlačítko u hlášky sice doskočí samo, ale text musí sedět
 * i pro toho, kdo si cestu proklikává ručně.
 *
 * `where === null` znamená „přímo v tomhle formuláři".
 */
final class PayrollRegistrationFieldVocabulary
{
    public const WHERE_IDENTITY = 'kartě osoby → Identita a adresy → '
        . 'Historie jména → Údaje pro registraci zaměstnance';
    public const WHERE_NAMES = 'kartě osoby → Identita a adresy → Historie jména';
    public const WHERE_ADDRESSES = 'kartě osoby → Identita a adresy → Historie adres';
    public const WHERE_IDENTIFIERS = 'kartě osoby → Kontakty a identifikátory';
    public const WHERE_HEALTH_INSURANCE = 'kartě osoby → Zákonná evidence → zdravotní pojištění';
    public const WHERE_TAX_RESIDENCY = 'kartě osoby → Zákonná evidence → daňová rezidence';
    public const WHERE_RELATIONSHIP = 'kartě pracovního vztahu';
    public const WHERE_RELATIONSHIP_TERMS = 'kartě pracovního vztahu → sjednané podmínky';
    /**
     * OIČ a ID PPV se NEzadávají u osoby, ale u pracovního vztahu — panel
     * `EmploymentJmhzIdentityPanel.vue`, sekce `payroll.people.jmhz_identity.title`.
     * Poslat účetní na „Kontakty a identifikátory" by ji nechalo hledat pole,
     * které tam není.
     */
    public const WHERE_JMHZ_IDENTIFIERS = 'kartě pracovního vztahu → '
        . 'Identifikátory přidělené ČSSZ pro JMHZ';
    /**
     * Ověřeno proti UI, ne odhadnuto: v levém menu je „Mzdy → Nastavení mezd"
     * (`nav.payroll_settings`, routa `payroll-settings`) a variabilní symbol
     * i kód správy sociálního zabezpečení se zadávají na záložce
     * „Zaměstnavatel a účtárny" (`payroll.employer.tabs.employer`).
     * Dřívější „nastavení firmy → Mzdy → údaje pro ČSSZ" v aplikaci neexistuje.
     */
    public const WHERE_EMPLOYER = 'Mzdy → Nastavení mezd → '
        . 'Zaměstnavatel a účtárny';

    /**
     * Lidský název a místo zadání pro CELOU cestu.
     *
     * Klíč bez překladu se vypíše tak, jak je; neúplný slovník nesmí zamlčet,
     * že něco chybí.
     *
     * @var array<string,array{0:string,1:?string}>
     */
    private const FIELDS = [
        // Identita osoby.
        'identity' => ['evidence identity osoby', self::WHERE_NAMES],
        'citizenship_country_code' => ['státní občanství', self::WHERE_IDENTITY],
        'birth_country_code' => ['stát narození', self::WHERE_IDENTITY],
        'birth_place' => ['místo narození', self::WHERE_IDENTITY],
        'birth_date' => ['datum narození', self::WHERE_IDENTITY],
        'sex' => ['pohlaví', self::WHERE_IDENTITY],
        'family_name' => ['příjmení', self::WHERE_NAMES],
        'given_name' => ['jméno', self::WHERE_NAMES],
        'first_name' => ['jméno', self::WHERE_NAMES],
        'last_name' => ['příjmení', self::WHERE_NAMES],
        'birth_surname' => ['rodné příjmení', self::WHERE_NAMES],
        'previous_surnames' => ['dřívější příjmení', self::WHERE_NAMES],
        'title_prefix' => ['titul před jménem', self::WHERE_NAMES],
        'title_suffix' => ['titul za jménem', self::WHERE_NAMES],

        // Identifikátory osoby.
        'birth_number' => ['rodné číslo', self::WHERE_IDENTIFIERS],
        'ecp' => ['evidenční číslo pojištěnce (EČP)', self::WHERE_IDENTIFIERS],
        'vcp' => ['variabilní číslo pojištěnce (VČP)', self::WHERE_IDENTIFIERS],
        'foreign_tax_identifier' => [
            'zahraniční daňový identifikátor',
            self::WHERE_IDENTIFIERS,
        ],
        'person_external_identifier' => [
            'osobní identifikační číslo od ČSSZ (OIČ / IK MPSV)',
            self::WHERE_JMHZ_IDENTIFIERS,
        ],
        'employment_external_identifier' => [
            'identifikátor pracovního vztahu od ČSSZ (ID PPV)',
            self::WHERE_JMHZ_IDENTIFIERS,
        ],

        // Trvalý pobyt.
        'permanent_address.street' => ['ulice trvalého pobytu', self::WHERE_ADDRESSES],
        'permanent_address.house_number' => ['číslo popisné trvalého pobytu', null],
        'permanent_address.city' => ['obec trvalého pobytu', self::WHERE_ADDRESSES],
        'permanent_address.postal_code' => ['PSČ trvalého pobytu', self::WHERE_ADDRESSES],
        'permanent_address.country_code' => ['stát trvalého pobytu', self::WHERE_ADDRESSES],

        // Zákonná evidence.
        'health_insurance_code' => [
            'kód zdravotní pojišťovny',
            self::WHERE_HEALTH_INSURANCE,
        ],
        'tax_residency.country_code' => [
            'stát daňové rezidence',
            self::WHERE_TAX_RESIDENCY,
        ],
        'tax_residency.identifier_type' => [
            'typ zahraničního daňového identifikátoru',
            null,
        ],
        'tax_residency.identifier' => ['zahraniční daňový identifikátor', null],
        'tax_residency.identifier_pair' => [
            'typ a hodnota zahraničního daňového identifikátoru (nutné obojí, nebo nic)',
            null,
        ],
        'tax_residency.residence_address' => [
            'adresa bydliště ve státě daňové rezidence',
            null,
        ],

        // Pracovní vztah.
        'employment.activity_code' => [
            'druh činnosti pro ČSSZ',
            self::WHERE_RELATIONSHIP_TERMS,
        ],
        'employment.relationship_detail_code' => [
            'bližší určení pracovněprávního vztahu',
            self::WHERE_RELATIONSHIP_TERMS,
        ],
        'employment.actual_start_on' => [
            'skutečné datum nástupu',
            self::WHERE_RELATIONSHIP,
        ],
        'employment.contract_start_on' => ['sjednaný den nástupu', null],
        'employment.small_scale' => ['příznak zaměstnání malého rozsahu', null],
        'employment.employment_status_code' => ['postavení zaměstnance', null],
        'employment.work_mode_code' => ['režim práce', null],
        'employment.continuous_operation' => ['nepřetržitý provoz', null],
        'employment.prevailing_workplace_code' => ['převažující pracoviště', null],
        'employment.expected_workplaces' => ['předpokládaná pracoviště', null],
        'employment.contract_workplace' => ['sjednané místo výkonu práce', null],
        'employment.workplace_city' => ['obec pracoviště', null],
        'employment.workplace_municipality_code' => ['kód obce pracoviště', null],
        'employment.profession_code' => ['profese (CZ-ISCO)', null],
        'employment.required_education_code' => ['požadované vzdělání', null],
        'employment.position_name' => ['název pracovní pozice', null],
        'employment.leadership' => ['příznak vedoucí pozice', null],
        'employment.end_on' => ['datum skončení pracovního vztahu', null],

        // Skutečnosti o osobě.
        'facts.highest_education_code' => ['nejvyšší dosažené vzdělání', null],
        'facts.disability_card' => ['průkaz osoby se zdravotním postižením', null],
        'facts.health_restrictions' => ['seznam zdravotních omezení', null],
        'facts.health_restrictions[]' => ['položka seznamu zdravotních omezení', null],

        // Důchod.
        'pension.type_code' => ['druh důchodu', null],
        'pension.received_from' => ['datum přiznání důchodu', null],
        'pension.type_and_received_from' => [
            'druh důchodu a datum přiznání (nutné obojí, nebo nic)',
            null,
        ],
        'pension.early_retirement' => ['příznak předčasného důchodu', null],
        'pension.reduced_retirement_age' => ['příznak snížené důchodové hranice', null],

        // Zahraniční legislativa.
        'foreign_legislation.applies' => ['příznak zahraniční legislativy', null],
        'foreign_legislation.country_code' => ['stát zahraniční legislativy', null],
        'foreign_legislation.identifier' => [
            'identifikátor zahraničního pojištění',
            null,
        ],

        // Celé sekce formuláře. Hlásí se, když nedorazí vůbec — bez nich by
        // hláška zněla „Údaj employment chybí".
        'source' => ['podklad registrace', null],
        // Vnitřek podkladu účetní nevyplňuje — pojmenované jsou jen proto, aby
        // při porušené integritě nevypadla hláška „Údaj source.row_version".
        'source.source_key' => ['druh podkladu registrace', null],
        'source.source_id' => ['číslo podkladu registrace', null],
        'source.row_version' => ['verze podkladu registrace', null],
        'source.reference_hash' => ['kontrolní otisk podkladu registrace', null],
        'source.supplier_id' => ['firma, které podklad patří', null],
        'source.employee_id' => ['osoba, které podklad patří', null],
        'source.employment_id' => ['pracovní vztah, kterému podklad patří', null],
        'source.effective_on' => ['den, ke kterému je podklad zmrazený', null],
        'employment' => ['sekce Pracovní vztah', self::WHERE_RELATIONSHIP],
        'permanent_address' => ['adresa trvalého pobytu', self::WHERE_ADDRESSES],
        'tax_residency' => ['daňová rezidence', self::WHERE_TAX_RESIDENCY],
        'facts' => ['sekce Skutečnosti o zaměstnanci', null],
        'pension' => ['sekce Důchod', null],
        'foreign_legislation' => ['sekce Zahraniční legislativa', null],

        // Doklad totožnosti.
        'proof_identity' => ['doklad totožnosti', null],
        'proof_identity.type_code' => ['typ dokladu totožnosti', null],
        'proof_identity.number' => ['číslo dokladu totožnosti', null],
        'proof_identity.foreign_issuer' => ['zahraniční vydavatel dokladu', null],
        'proof_identity.country_code' => ['stát vydání dokladu totožnosti', null],

        // Přístup na trh práce.
        'foreign_worker' => ['rozhodnutí o přístupu na trh práce', null],
        'foreign_worker.free_access' => ['volný přístup na trh práce', null],
        'foreign_worker.free_access_reason_code' => ['důvod volného přístupu na trh práce', null],
        'foreign_worker.permit' => [
            'úplné povolení k zaměstnání (typ, číslo, platnost od a do)',
            null,
        ],
        'foreign_worker.permit_type_code' => ['typ povolení k zaměstnání', null],
        'foreign_worker.permit_identifier' => ['číslo povolení k zaměstnání', null],
        'foreign_worker.permit_from' => ['platnost povolení k zaměstnání od', null],
        'foreign_worker.permit_to' => ['platnost povolení k zaměstnání do', null],
        'foreign_worker.issuing_labour_office_code' => [
            'kód úřadu práce, který povolení vydal',
            null,
        ],

        // Adresní bloky, které se vyplňují přímo tady.
        'czech_residence_address' => ['adresa pobytu v ČR', null],
        'contact_address' => ['kontaktní adresa', null],

        // Přílohy.
        'attachments' => ['přílohy registrace', null],
        'attachments.[]' => ['příloha registrace', null],
        'attachments.name' => ['název přílohy', null],
        'attachments.description' => ['popis přílohy', null],
        'attachments.data_base64' => ['obsah přílohy', null],

        // Zkrácené názvy, jak je nesou data události (A2–A8) a formulářový vstup.
        'activity_code' => ['druh činnosti pro ČSSZ', self::WHERE_RELATIONSHIP_TERMS],
        'relationship_detail_code' => [
            'bližší určení pracovněprávního vztahu',
            self::WHERE_RELATIONSHIP_TERMS,
        ],
        'actual_start_on' => ['skutečné datum nástupu', self::WHERE_RELATIONSHIP],
        'contract_start_on' => ['sjednaný den nástupu', self::WHERE_RELATIONSHIP],
        'end_on' => ['datum skončení pracovního vztahu', self::WHERE_RELATIONSHIP],
        'effective_on' => ['den, ke kterému se změna hlásí', null],
        'notification_trigger_on' => ['den, kdy začala běžet lhůta pro oznámení', null],
        'expected_start_on' => ['předpokládané datum nástupu', self::WHERE_RELATIONSHIP],

        // Údaje zaměstnavatele.
        'employer_variable_symbol' => [
            'variabilní symbol zaměstnavatele u ČSSZ',
            self::WHERE_EMPLOYER,
        ],
        'employer_name' => ['název zaměstnavatele', self::WHERE_EMPLOYER],
        'cssz_workplace_code' => ['kód pracoviště ČSSZ', self::WHERE_EMPLOYER],
        'new_variable_symbol' => ['nový variabilní symbol zaměstnavatele', null],

        // Formulář registračních událostí A2–A8.
        'interaction' => ['druh registrační události', null],
        'environment' => ['prostředí podání', null],
        'source_reference' => ['reference ověřeného podkladu', null],
        'source_submission_id' => ['číslo původního přijatého podání', null],
        'source_filing_on' => ['datum původního přijatého podání', null],
        'discovered_on' => ['datum zjištění chyby', null],
        'planned_start_on' => [
            'původní plánovaný den nástupu',
            self::WHERE_RELATIONSHIP,
        ],
        'not_started' => ['potvrzení, že zaměstnanec vůbec nenastoupil', null],
        'ended_by_death' => ['ukončení pracovního vztahu úmrtím', null],
        'changes' => ['seznam měněných údajů', null],
        'corrections' => ['seznam opravovaných údajů', null],
        'highest_education_code' => ['nejvyšší dosažené vzdělání', null],
        'tax_residency.changed_on' => ['datum změny daňové rezidence', null],
        'terms_reference' => [
            'odkaz na sjednané podmínky pracovního vztahu',
            self::WHERE_RELATIONSHIP_TERMS,
        ],

        // Podklady pro podporu v nezaměstnanosti, které se přikládají k A2.
        'unemployment' => ['podklady pro podporu v nezaměstnanosti', null],
        'unemployment.mode' => [
            'režim podkladů pro podporu v nezaměstnanosti',
            null,
        ],
        'unemployment.average_net_earnings' => [
            'průměrný čistý měsíční výdělek',
            null,
        ],
        'unemployment.employment_type' => [
            'druh zaměstnání v podkladech pro podporu',
            null,
        ],
        'unemployment.termination_reason' => [
            'důvod skončení pracovního vztahu',
            null,
        ],
        'unemployment.service_termination_reason' => [
            'důvod skončení služebního poměru',
            null,
        ],
        'unemployment.early_termination_reason' => [
            'důvod předčasného skončení',
            null,
        ],
        'unemployment.entitlement' => ['nárok na odstupné', null],
        'unemployment.paid_in_full' => ['vyplacení plnění v plné výši', null],
        'unemployment.replacement' => ['náhrada', null],
        'unemployment.golden_handshake' => ['odchodné', null],
        'unemployment.severance_pay' => ['odstupné', null],
        'unemployment.disposal' => ['odbytné', null],
        'unemployment.pension_periods' => [
            'intervaly důchodového pojištění',
            null,
        ],
        'unemployment.pension_periods[]' => [
            'interval důchodového pojištění',
            null,
        ],
        'unemployment.pension_periods[].from' => [
            'počátek intervalu důchodového pojištění',
            null,
        ],
        'unemployment.pension_periods[].to' => [
            'konec intervalu důchodového pojištění',
            null,
        ],

        // Zahraniční nositel pojištění (A6 a A7).
        'foreign_insurance' => ['zahraniční nositel pojištění', null],
        'foreign_insurance.current' => [
            'specifikace zahraničního nositele pojištění',
            null,
        ],
        'foreign_insurance.name' => [
            'název zahraničního nositele pojištění',
            null,
        ],
        'foreign_insurance.country_code' => [
            'stát zahraničního nositele pojištění',
            null,
        ],
        'foreign_insurance.identifier' => ['číslo zahraničního pojištění', null],
        'foreign_insurance.sector' => [
            'část obce nebo správní oblast zahraničního nositele',
            null,
        ],
    ];

    /**
     * Adresní listy se opakují ve čtyřech sekcích. Trvalý pobyt má vlastní
     * záznamy výš (bere se z evidence osoby), zbytek se vyplňuje tady.
     *
     * @var array<string,string>
     */
    private const ADDRESS_LEAVES = [
        'street' => 'ulice',
        'house_number' => 'číslo popisné',
        'orientation_number' => 'číslo orientační',
        'city' => 'obec',
        'postal_code' => 'PSČ',
        'country_code' => 'stát adresy',
        'ruian_point' => 'kód adresního místa RÚIAN',
    ];

    /**
     * Kontejnery, které jsou jen technickou obálkou nad stejným polem.
     *
     * Snapshot identity nese `identity.first_name`, formulář posílá
     * `first_name` a katalog změn `identifiers.ecp` — pro účetní jde pořád
     * o tentýž údaj, tak ať dostane tentýž popis.
     *
     * @var list<string>
     */
    private const TRANSPARENT_PREFIXES = [
        'identity.',
        'identifiers.',
        'source.',
        'data.',
        'delta.',
        'snapshot.',
    ];

    /**
     * Lidský název podání podle kódu akce.
     *
     * „REGZEC A5" účetní nic neříká; věta musí začínat tím, co se vlastně
     * oznamuje. Kód zůstává v závorce, aby se dal spárovat s pokyny ČSSZ.
     *
     * @var array<string,array<int,string>>
     */
    private const ACTIONS = [
        'REGZEC25' => [
            1 => 'Přihlášení zaměstnance (REGZEC A1)',
            2 => 'Oznámení o skončení pracovního vztahu (REGZEC A2)',
            3 => 'Oznámení o změně údajů zaměstnance (REGZEC A3)',
            4 => 'Oprava dříve oznámených údajů (REGZEC A4)',
            5 => 'Převod zaměstnance pod jiný variabilní symbol zaměstnavatele'
                . ' (REGZEC A5)',
            6 => 'Oznámení o vzniku příslušnosti k českým předpisům (REGZEC A6)',
            7 => 'Oznámení o skončení příslušnosti k českým předpisům'
                . ' (REGZEC A7)',
            8 => 'Storno přihlášení — zaměstnanec nenastoupil (REGZEC A8)',
        ],
        'PREZEC26' => [
            9 => 'Částečné přihlášení před nástupem (PREZEC P1)',
            10 => 'Oznámení, že zaměstnanec nenastoupil (PREZEC P2)',
        ],
    ];

    /** Lidský název podání; pro neznámou kombinaci vrátí aspoň kód. */
    public static function action(string $documentType, int $actionCode): string
    {
        return self::ACTIONS[$documentType][$actionCode]
            ?? "Podání {$documentType} s kódem akce {$actionCode}";
    }

    /** Lidský název údaje, `null` když ho slovník nezná. */
    public static function name(string $path): ?string
    {
        return self::entry($path)[0] ?? null;
    }

    /**
     * Lidský název s velkým počátečním písmenem, aby mohl začínat větu.
     *
     * Hlášky musí začínat názvem údaje, ne názvem sloupce — proto se
     * kapitalizuje tady, ne u každého volajícího zvlášť.
     */
    public static function capitalName(string $path): ?string
    {
        $name = self::name($path);

        return $name === null ? null : mb_ucfirst($name);
    }

    /**
     * Název pro začátek věty; pro neznámou cestu vrátí aspoň čitelnou náhradu.
     */
    public static function label(string $path): string
    {
        return self::capitalName($path) ?? 'Údaj ' . $path;
    }

    /** Kde se údaj zadává; `null` = přímo v tomhle formuláři. */
    public static function where(string $path): ?string
    {
        $entry = self::entry($path);

        return $entry === null ? null : $entry[1];
    }

    /**
     * Hotová věta „kam jít". Neznámou cestu neumíme nasměrovat přesně, tak
     * pošleme účetní na obě karty místo mlčení.
     */
    public static function describe(string $path): string
    {
        $entry = self::entry($path);
        // Bez pronomen: názvy jsou různých rodů („adresa", „kód", „vzdělání")
        // a jedna společná věta by u dvou třetin z nich byla gramaticky špatně.
        if ($entry === null) {
            return 'Údaj najdete v kartě osoby nebo v kartě pracovního vztahu.';
        }
        if ($entry[1] === null) {
            return 'Údaj vyplňte přímo v tomhle formuláři.';
        }

        return "Údaj doplňte na {$entry[1]} — v tomhle formuláři se nezadává.";
    }

    /**
     * Technický název do závorky na konec věty. Nikdy nesmí větu začínat,
     * ale zmizet nesmí — frontend podle něj skáče na konkrétní vstup
     * a v podpoře se podle něj pole dohledá.
     */
    public static function reference(?string $path): string
    {
        return $path === null || $path === '' ? '' : " ({$path})";
    }

    /** @return array{0:string,1:?string}|null */
    private static function entry(string $path): ?array
    {
        if (isset(self::FIELDS[$path])) {
            return self::FIELDS[$path];
        }
        foreach (self::TRANSPARENT_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $stripped = substr($path, strlen($prefix));
                if (isset(self::FIELDS[$stripped])) {
                    return self::FIELDS[$stripped];
                }
            }
        }
        $dot = strrpos($path, '.');
        if ($dot === false) {
            return null;
        }
        $leaf = substr($path, $dot + 1);
        $address = self::ADDRESS_LEAVES[$leaf] ?? null;

        return $address === null ? null : [$address, null];
    }
}
