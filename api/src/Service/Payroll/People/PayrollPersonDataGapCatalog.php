<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\People;

/**
 * JEDINÝ seznam toho, co osobě chybí — a jak naléhavě.
 *
 * ── Co bylo špatně ──────────────────────────────────────────────────────────
 * Chybějící údaj se účetní hlásil vždycky až na konci cesty: u podání, u výpočtu
 * nebo u plateb. Do té doby o něm nevěděla, protože seznam lidí uměl jen jeden
 * hrubý štítek „Vyžaduje doplnění" nad čtyřmi podmínkami uložení profilu, a karta
 * osoby neuměla ani to. Zbylá pravidla („ověřená pojišťovna", „daňová rezidence",
 * „ověřený výplatní účet") žila rozsypaná po stavitelích podání a po kontrole
 * před během — každé svým vlastním SQL a svou vlastní formulací.
 *
 * ── Pravidlo teď ────────────────────────────────────────────────────────────
 * Katalog je jedno místo, ze kterého čtou VŠICHNI: seznam osob (štítek s počty
 * a filtr), karta osoby (výčet „co doplnit") i kontrola před zahájením běhu
 * ({@see \MyInvoice\Service\Payroll\Run\PayrollRunReadinessService}). Dvě kopie
 * pravidel se dřív nebo později rozejdou a obrazovky si začnou odporovat.
 *
 * Pravidla jsou SQL výrazy, ne PHP dotazy per osoba: seznam je stránkovaný a
 * musí umět zúžit i spočítat celou firmu jedním dotazem. Výraz je pravdivý,
 * když údaj CHYBÍ.
 *
 * ── Co se sem smí zapsat ────────────────────────────────────────────────────
 * Jen údaj, který vyžaduje zákon nebo příjemce podání (ČSSZ, zdravotní
 * pojišťovna, finanční úřad, zaměstnanec sám). „Potřebujeme to my" důvod není.
 * Nic z toho NEBLOKUJE uložení — chybějící údaj je informace, ne závora.
 *
 * ── Dvě úrovně ──────────────────────────────────────────────────────────────
 * - `blocking`  — bez toho měsíc neprojde: podání se neodešle nebo se nespočítá.
 * - `advisory`  — doplní se, až bude čas; nic se kvůli tomu nezastaví.
 *
 * `panel`/`field` je adresa místa, kde se údaj vyplňuje — seznam i karta z nich
 * skládají povel `?person=&panel=&field=`, který zpracovává `PeopleList.focusPanel()`
 * a doskočí na něj `web/src/utils/revealField.ts`. Lidský název údaje se sem
 * NEPÍŠE: texty patří do i18n (`payroll.people.data_gap.*`), klíč je smlouva.
 */
final class PayrollPersonDataGapCatalog
{
    /** Bez toho měsíc neprojde — podání se neodešle nebo se nespočítá. */
    public const SEVERITY_BLOCKING = 'blocking';

    /** Chybí, ale nic se kvůli tomu nezastaví. */
    public const SEVERITY_ADVISORY = 'advisory';

    /** Předpona sloupců, pod kterými výrazy jezdí v SELECTu seznamu. */
    public const COLUMN_PREFIX = 'data_gap_';

    /**
     * Naléhavosti, jak je zná API i klient — pár k unionu
     * `PayrollPersonDataGapSeverity` ve `web/src/api/payroll.ts`.
     *
     * @var list<string>
     */
    public const SEVERITIES = [self::SEVERITY_BLOCKING, self::SEVERITY_ADVISORY];

    /**
     * Klíče katalogu v pořadí, ve kterém se má doplňovat — pár k unionu
     * `PayrollPersonDataGapKey` ve `web/src/api/payroll.ts`.
     *
     * PHP neumí v konstantním výrazu zavolat `array_keys()`, takže je výčet
     * napsaný podruhé; {@see self::keys()} hlídá, že se s katalogem nerozešel.
     *
     * @var list<string>
     */
    public const KEYS = [
        'employment',
        'name',
        'identifier',
        'residence',
        'health_insurance',
        'tax_residence',
        'payout_account',
        'contact',
        'citizenship',
        'birth_date',
        'tax_declaration',
        'jmhz_person_identifier',
        'jmhz_employment_identifier',
        'jmhz_activity_code',
    ];

    /**
     * Prostředí, ve kterém se hlídají identifikátory od ČSSZ.
     *
     * Testovací a produkční hodnoty jsou oddělené evidence. Značka v seznamu
     * mluví o skutečné povinnosti, ne o nacvičování — proto produkční.
     */
    private const JMHZ_ENVIRONMENT = 'production';

    /**
     * Katalog v pořadí, ve kterém se má doplňovat.
     *
     * Pořadí kopíruje skutečný pracovní postup: bez pracovního vztahu není co
     * zpracovat, pak teprve zákonná identita, pojišťovna a peníze. Karta i
     * seznam z něj berou „další krok", takže se nemůžou rozejít v tom, čím začít.
     *
     * `legacy_setup` označuje pět mezer, které nese starší pole `setup_gaps` a
     * které vynucuje {@see \MyInvoice\Repository\Payroll\PayrollPersonProfileRepository::assertReadyProfile()}.
     * Zůstávají tady, aby existoval JEDEN seznam pravidel, ne dva.
     *
     * @return array<string, array{
     *   severity:string, panel:?string, field:?string, legacy_setup:bool
     * }>
     */
    public static function definitions(): array
    {
        return [
            // Bez vztahu nemá kdo pracovat ani co odvádět — první krok vždycky.
            'employment' => [
                'severity' => self::SEVERITY_BLOCKING,
                'panel' => null,
                'field' => null,
                'legacy_setup' => true,
            ],
            // Jméno a příjmení zvlášť: podání je neumí vyčíst z jednoho řádku.
            'name' => [
                'severity' => self::SEVERITY_BLOCKING,
                'panel' => 'registration_identity',
                'field' => 'identity.last_name',
                'legacy_setup' => true,
            ],
            // RČ / EČP / VČP — bez identifikátoru osobu ČSSZ ani pojišťovna nepřijme.
            'identifier' => [
                'severity' => self::SEVERITY_BLOCKING,
                'panel' => 'identifiers',
                'field' => 'identifier.value',
                'legacy_setup' => true,
            ],
            // Adresa trvalého pobytu je povinná náležitost přihlášky i ELDP.
            'residence' => [
                'severity' => self::SEVERITY_BLOCKING,
                'panel' => 'addresses',
                'field' => null,
                'legacy_setup' => true,
            ],
            // Bez ověřené pojišťovny se nedá spočítat ani odvést zdravotní pojistné.
            'health_insurance' => [
                'severity' => self::SEVERITY_BLOCKING,
                'panel' => 'statutory_evidence',
                'field' => 'statutory.health_coverages',
                'legacy_setup' => false,
            ],
            // Rezident/nerezident rozhoduje o sazbě i o způsobu zdanění.
            'tax_residence' => [
                'severity' => self::SEVERITY_BLOCKING,
                'panel' => 'statutory_evidence',
                'field' => 'statutory.tax_residences',
                'legacy_setup' => false,
            ],
            // Mzda na účet bez ověřeného účtu = běh spočítá, ale zaplatit nejde.
            'payout_account' => [
                'severity' => self::SEVERITY_BLOCKING,
                'panel' => 'accounts',
                'field' => 'payout.bank_account',
                'legacy_setup' => false,
            ],
            // Kontakt potřebuje doručení výplatní pásky, ne podání — proto jen rada.
            'contact' => [
                'severity' => self::SEVERITY_ADVISORY,
                'panel' => 'contacts',
                'field' => 'contact.value',
                'legacy_setup' => true,
            ],
            // Občanství rozhoduje o povinných skupinách přihlášky (REGZEC A1).
            'citizenship' => [
                'severity' => self::SEVERITY_ADVISORY,
                'panel' => 'registration_identity',
                'field' => 'identity.citizenship_country_code',
                'legacy_setup' => false,
            ],
            // Datum narození nese u osoby bez RČ identifikaci v podáních.
            'birth_date' => [
                'severity' => self::SEVERITY_ADVISORY,
                'panel' => 'registration_identity',
                'field' => 'identity.birth_date',
                'legacy_setup' => false,
            ],
            // Bez rozhodnutého prohlášení se jen neuplatní měsíční slevy.
            'tax_declaration' => [
                'severity' => self::SEVERITY_ADVISORY,
                'panel' => 'statutory_evidence',
                'field' => 'statutory.tax_declarations',
                'legacy_setup' => false,
            ],
            /*
             * ── Údaje na PRACOVNÍM VZTAHU, ne na osobě ──────────────────────
             * Katalog je jinak per osoba; tyhle tři leží na kartě vztahu.
             * Hlásí se proto AGREGOVANĚ — „aspoň jeden aktivní vztah té osoby
             * to nemá". Seznam osob má jeden řádek na člověka a rozepsat do něj
             * dva vztahy nejde; kdo má vztahů víc, dohledá je na kartě.
             *
             * Všechny tři jsou `advisory`, protože se dají doplnit kdykoli před
             * podáním a s mzdovým VÝPOČTEM nemají nic společného. OIČ a ID PPV
             * navíc přiděluje ČSSZ, ne účetní — dokud je nepřidělila, hlásí se
             * zaměstnanec jménem (větev `identifikaceType` v XSD JMHZ), takže
             * to není chyba k vyplnění, ale stav k prohlédnutí.
             */
            'jmhz_person_identifier' => [
                'severity' => self::SEVERITY_ADVISORY,
                'panel' => 'jmhz_identity',
                'field' => 'jmhz.person_external_identifier',
                'legacy_setup' => false,
            ],
            'jmhz_employment_identifier' => [
                'severity' => self::SEVERITY_ADVISORY,
                'panel' => 'jmhz_identity',
                'field' => 'jmhz.employment_external_identifier',
                'legacy_setup' => false,
            ],
            /*
             * Druh činnosti (10239) je povinná náležitost měsíčního hlášení
             * i evidence důchodového pojištění — a je to VOLBA účetní, aplikace
             * ji nemá odkud vzít.
             */
            'jmhz_activity_code' => [
                'severity' => self::SEVERITY_ADVISORY,
                'panel' => 'employment_terms',
                'field' => 'employment.activity_code',
                'legacy_setup' => false,
            ],
        ];
    }

    /**
     * Klíče v pořadí katalogu.
     *
     * Hlídá zároveň, že se ručně psaný výčet {@see self::KEYS} nerozešel
     * s katalogem. Rozejít se můžou tiše — a tichý rozdíl by znamenal mezeru,
     * kterou seznam počítá, ale klient pro ni nemá název, nebo naopak.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        $keys = array_keys(self::definitions());
        if ($keys !== self::KEYS) {
            throw new \LogicException(
                'Katalog chybějících údajů osoby se rozešel s výčtem KEYS.',
            );
        }

        return $keys;
    }

    /** Klíče starého pole `setup_gaps`. @return list<string> */
    public static function legacySetupKeys(): array
    {
        $keys = [];
        foreach (self::definitions() as $key => $definition) {
            if ($definition['legacy_setup']) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Popis jedné mezery pro frontend — bez textu, jen klíč, naléhavost a adresa.
     *
     * @return array{key:string,severity:string,panel:?string,field:?string}
     */
    public static function describe(string $key): array
    {
        $definition = self::definitions()[$key]
            ?? throw new \InvalidArgumentException("Neznámá mezera v údajích osoby: {$key}.");

        return [
            'key' => $key,
            'severity' => $definition['severity'],
            'panel' => $definition['panel'],
            'field' => $definition['field'],
        ];
    }

    /**
     * SQL výrazy „tenhle údaj CHYBÍ", jeden na mezeru.
     *
     * `$employee` je alias tabulky `payroll_employees` v dotazu volajícího,
     * `$profile` alias `payroll_employee_profiles`. Nejsou to uživatelské vstupy
     * — dosazuje je volající repozitář ze svého vlastního SQL.
     *
     * @return array<string,string>
     */
    public static function expressions(
        string $employee = 'employee',
        string $profile = 'profile',
    ): array {
        $hasRelation = self::hasEmploymentExpression($employee);

        return [
            'employment' => sprintf(
                'NOT EXISTS (
                SELECT 1 FROM payroll_employments gap
                 WHERE gap.supplier_id = %1$s.supplier_id
                   AND gap.employee_id = %1$s.id)',
                $employee,
            ),
            'name' => sprintf(
                "NOT EXISTS (
                SELECT 1 FROM payroll_person_identity_history gap
                 WHERE gap.supplier_id = %1\$s.supplier_id
                   AND gap.employee_id = %1\$s.id
                   AND gap.first_name IS NOT NULL AND gap.first_name <> ''
                   AND gap.last_name IS NOT NULL AND gap.last_name <> ''
                   AND gap.effective_from <= CURRENT_DATE
                   AND (gap.effective_to IS NULL OR gap.effective_to >= CURRENT_DATE))",
                $employee,
            ),
            'identifier' => sprintf(
                "NOT EXISTS (
                SELECT 1 FROM payroll_person_identifiers gap
                 WHERE gap.supplier_id = %1\$s.supplier_id
                   AND gap.employee_id = %1\$s.id
                   AND gap.identifier_type IN ('birth_number', 'ecp', 'vcp'))",
                $employee,
            ),
            'residence' => sprintf(
                "NOT EXISTS (
                SELECT 1 FROM payroll_person_addresses gap
                 WHERE gap.supplier_id = %1\$s.supplier_id
                   AND gap.employee_id = %1\$s.id
                   AND gap.address_type = 'residence'
                   AND gap.effective_from <= CURRENT_DATE
                   AND (gap.effective_to IS NULL OR gap.effective_to >= CURRENT_DATE))",
                $employee,
            ),
            /*
             * `not_applicable` je plnohodnotná odpověď, ne díra: u osoby v cizím
             * systému pojištění se česká pojišťovna nevyplňuje a hlásit ji jako
             * chybějící by byl nesmysl, který nejde umlčet.
             */
            'health_insurance' => sprintf(
                "%2\$s AND NOT EXISTS (
                SELECT 1 FROM payroll_person_health_coverage_history gap
                 WHERE gap.supplier_id = %1\$s.supplier_id
                   AND gap.employee_id = %1\$s.id
                   AND gap.effective_from <= CURRENT_DATE
                   AND (gap.effective_to IS NULL OR gap.effective_to >= CURRENT_DATE)
                   AND (
                       (gap.insurer_status = 'verified' AND gap.insurer_code IS NOT NULL)
                       OR gap.insurer_status = 'not_applicable'
                   ))",
                $employee,
                $hasRelation,
            ),
            'tax_residence' => sprintf(
                "%2\$s AND NOT EXISTS (
                SELECT 1 FROM payroll_person_tax_residences gap
                 WHERE gap.supplier_id = %1\$s.supplier_id
                   AND gap.employee_id = %1\$s.id
                   AND gap.residence <> 'unverified'
                   AND gap.effective_from <= CURRENT_DATE
                   AND (gap.effective_to IS NULL OR gap.effective_to >= CURRENT_DATE))",
                $employee,
                $hasRelation,
            ),
            /*
             * Hlásí se jen tomu, kdo mzdu na účet OPRAVDU posílá. U hotovostní
             * výplaty je chybějící účet správný stav, ne mezera; `COALESCE`
             * drží tenhle výklad i pro osobu, která kartu profilu ještě nemá.
             */
            'payout_account' => sprintf(
                "COALESCE(%2\$s.payout_method, 'cash') IN ('bank', 'mixed')
                 AND NOT EXISTS (
                SELECT 1 FROM payroll_person_accounts gap
                 WHERE gap.supplier_id = %1\$s.supplier_id
                   AND gap.employee_id = %1\$s.id
                   AND gap.is_active = 1
                   AND gap.verified_on IS NOT NULL
                   AND gap.verification_source IS NOT NULL
                   AND gap.effective_from <= CURRENT_DATE
                   AND (gap.effective_to IS NULL OR gap.effective_to >= CURRENT_DATE))",
                $employee,
                $profile,
            ),
            'contact' => sprintf(
                'NOT EXISTS (
                SELECT 1 FROM payroll_person_contacts gap
                 WHERE gap.supplier_id = %1$s.supplier_id
                   AND gap.employee_id = %1$s.id
                   AND gap.is_active = 1 AND gap.is_primary = 1)',
                $employee,
            ),
            'citizenship' => sprintf(
                'NOT EXISTS (
                SELECT 1 FROM payroll_person_identity_history gap
                 WHERE gap.supplier_id = %1$s.supplier_id
                   AND gap.employee_id = %1$s.id
                   AND gap.citizenship_country_code IS NOT NULL
                   AND gap.effective_from <= CURRENT_DATE
                   AND (gap.effective_to IS NULL OR gap.effective_to >= CURRENT_DATE))',
                $employee,
            ),
            'birth_date' => sprintf(
                'NOT EXISTS (
                SELECT 1 FROM payroll_person_identity_history gap
                 WHERE gap.supplier_id = %1$s.supplier_id
                   AND gap.employee_id = %1$s.id
                   AND gap.birth_date IS NOT NULL
                   AND gap.effective_from <= CURRENT_DATE
                   AND (gap.effective_to IS NULL OR gap.effective_to >= CURRENT_DATE))',
                $employee,
            ),
            /*
             * `not-signed` je rozhodnutý stav, ne mezera — zaměstnanec prohlášení
             * podepsat nemusí. Chybí jen tam, kde není rozhodnuto vůbec.
             */
            'tax_declaration' => sprintf(
                "%2\$s AND NOT EXISTS (
                SELECT 1 FROM payroll_person_tax_declarations gap
                 WHERE gap.supplier_id = %1\$s.supplier_id
                   AND gap.employee_id = %1\$s.id
                   AND gap.status <> 'unverified'
                   AND gap.effective_from <= CURRENT_DATE
                   AND (gap.effective_to IS NULL OR gap.effective_to >= CURRENT_DATE))",
                $employee,
                $hasRelation,
            ),
            /*
             * ── Údaje na pracovním vztahu ───────────────────────────────────
             * Ptá se to zvenčí dovnitř: „má ta osoba aspoň jeden BĚŽÍCÍ vztah,
             * kterému tenhle údaj chybí?" Vztah, který ještě nezačal nebo už
             * skončil, se nehlásí — za toho se nic nepodává a nálezem by jen
             * zaplevelil seznam navždycky.
             */
            'jmhz_person_identifier' => sprintf(
                self::runningEmploymentGap(
                    "NOT EXISTS (
                    SELECT 1 FROM payroll_person_external_ids identifier
                     WHERE identifier.supplier_id = gap_relation.supplier_id
                       AND identifier.employee_id = gap_relation.employee_id
                       AND identifier.environment = '%2\$s'
                       AND identifier.identifier_type = 'ik_mpsv'
                       AND identifier.valid_from <= CURRENT_DATE
                       AND (identifier.valid_to IS NULL
                            OR identifier.valid_to >= CURRENT_DATE))",
                ),
                $employee,
                self::JMHZ_ENVIRONMENT,
            ),
            'jmhz_employment_identifier' => sprintf(
                self::runningEmploymentGap(
                    "NOT EXISTS (
                    SELECT 1 FROM payroll_employment_external_ids identifier
                     WHERE identifier.supplier_id = gap_relation.supplier_id
                       AND identifier.employment_id = gap_relation.id
                       AND identifier.environment = '%2\$s'
                       AND identifier.identifier_type = 'id_ppv'
                       AND identifier.valid_from <= CURRENT_DATE
                       AND (identifier.valid_to IS NULL
                            OR identifier.valid_to >= CURRENT_DATE))",
                ),
                $employee,
                self::JMHZ_ENVIRONMENT,
            ),
            'jmhz_activity_code' => sprintf(
                self::runningEmploymentGap(
                    "NOT EXISTS (
                    SELECT 1 FROM payroll_employment_terms term
                     WHERE term.supplier_id = gap_relation.supplier_id
                       AND term.employment_id = gap_relation.id
                       AND term.activity_code IS NOT NULL
                       AND term.activity_code <> ''
                       AND term.effective_from <= CURRENT_DATE
                       AND (term.effective_to IS NULL
                            OR term.effective_to >= CURRENT_DATE))",
                ),
                $employee,
            ),
        ];
    }

    /**
     * „Aspoň jeden běžící vztah osoby nemá …" — obal pro mezery na vztahu.
     *
     * Katalog vrací JEDNU sadu mezer na osobu, protože seznam má jeden řádek na
     * člověka. Údaje na vztahu ale existují na každý vztah zvlášť, a člověk jich
     * může mít víc. Značka proto říká „něco z toho chybí", ne „chybí to všude";
     * rozpis po vztazích patří na kartu osoby, kde je na něj místo.
     *
     * `%1$s` je alias `payroll_employees`, `%2$s` (když ho vnitřek použije)
     * prostředí. Vnitřní podmínka se váže na `gap_relation`.
     */
    private static function runningEmploymentGap(string $condition): string
    {
        return 'EXISTS (
                SELECT 1 FROM payroll_employments gap_relation
                 WHERE gap_relation.supplier_id = %1$s.supplier_id
                   AND gap_relation.employee_id = %1$s.id
                   AND gap_relation.status IN (\'preregistered\', \'active\', \'suspended\')
                   AND (gap_relation.start_date IS NULL
                        OR gap_relation.start_date <= CURRENT_DATE)
                   AND (gap_relation.end_date IS NULL
                        OR gap_relation.end_date >= CURRENT_DATE)
                   AND ' . $condition . ')';
    }

    /**
     * Sloupce `data_gap_*` do SELECTu seznamu.
     *
     * Jde to jedním dotazem přes celou stránku, ne řádek po řádku — jinak by
     * štítek stál tolik dotazů, kolik má firma zaměstnanců.
     */
    public static function selectColumns(
        string $employee = 'employee',
        string $profile = 'profile',
    ): string {
        $columns = [];
        foreach (self::expressions($employee, $profile) as $key => $expression) {
            $columns[] = "({$expression}) AS " . self::COLUMN_PREFIX . $key;
        }

        return implode(",\n                   ", $columns);
    }

    /**
     * Podmínka „osoba má aspoň jednu mezeru dané naléhavosti".
     *
     * Filtr seznamu ji staví nad TÝMIŽ výrazy, které vybírá SELECT, takže se
     * zúžení a štítek na řádku nemůžou rozejít. `$severity === null` znamená
     * jakoukoli mezeru.
     */
    public static function gapExpression(
        ?string $severity = null,
        string $employee = 'employee',
        string $profile = 'profile',
    ): string {
        $definitions = self::definitions();
        $parts = [];
        foreach (self::expressions($employee, $profile) as $key => $expression) {
            if ($severity !== null && $definitions[$key]['severity'] !== $severity) {
                continue;
            }
            $parts[] = "({$expression})";
        }
        if ($parts === []) {
            return '(1 = 0)';
        }

        return '(' . implode(' OR ', $parts) . ')';
    }

    /**
     * Mezery přečtené z řádku se sloupci `data_gap_*`.
     *
     * @param callable(string):bool $isSet vyhodnocení jednoho sloupce
     * @return list<array{key:string,severity:string,panel:?string,field:?string}>
     */
    public static function fromRow(callable $isSet): array
    {
        $gaps = [];
        foreach (self::keys() as $key) {
            if ($isSet(self::COLUMN_PREFIX . $key)) {
                $gaps[] = self::describe($key);
            }
        }

        return $gaps;
    }

    /**
     * Počty podle naléhavosti — to, co seznam kreslí do značky u řádku.
     *
     * @param list<array{key:string,severity:string,panel:?string,field:?string}> $gaps
     * @return array{blocking:int,advisory:int}
     */
    public static function counts(array $gaps): array
    {
        $counts = ['blocking' => 0, 'advisory' => 0];
        foreach ($gaps as $gap) {
            if ($gap['severity'] === self::SEVERITY_BLOCKING) {
                ++$counts['blocking'];
            } else {
                ++$counts['advisory'];
            }
        }

        return $counts;
    }

    /**
     * Povinnosti, které vznikají až pracovním vztahem.
     *
     * Osoba bez vztahu ještě nikoho nezajímá — hlásit u ní chybějící pojišťovnu
     * nebo daňovou rezidenci by byl šum, který nejde odklidit ničím jiným než
     * vymyšleným údajem.
     */
    private static function hasEmploymentExpression(string $employee): string
    {
        return sprintf(
            'EXISTS (
                SELECT 1 FROM payroll_employments gap_relation
                 WHERE gap_relation.supplier_id = %1$s.supplier_id
                   AND gap_relation.employee_id = %1$s.id)',
            $employee,
        );
    }
}
