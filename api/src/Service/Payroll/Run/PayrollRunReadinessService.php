<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository;
use MyInvoice\Repository\Payroll\PayrollPeopleRepository;
use MyInvoice\Service\Payroll\Payment\PayrollInstitutionVerificationWindow;
use MyInvoice\Service\Payroll\PayrollHistoricalPeriodService;
use MyInvoice\Service\Payroll\People\PayrollPersonDataGapCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzBlockerCatalog;
use PDO;

/**
 * KONTROLA PŘED ZAHÁJENÍM mzdového běhu.
 *
 * ── Co bylo špatně ──────────────────────────────────────────────────────────
 * Všechny kontroly běhu se dosud vyhodnocovaly až v okamžiku, kdy běh UŽ
 * existoval a jeho vstupy byly zmrazené. Účetní se tedy o chybějící registraci
 * účtárny u ČSSZ, o neschválené docházce nebo o rozpracovaných vstupech
 * dozvěděla ve třetí fázi — po dvou kliknutích, které mezitím zamkly snímek.
 * Cesta zpátky vedla přes zrušení běhu a novou revizi. Dvě z těch věcí
 * (chybějící zaměstnavatelská politika, chybějící ověřený účet instituce)
 * dokonce nebyly validace, ale výjimky: první shodila zamykání, druhá se
 * ozvala až u přípravy plateb, tedy o čtyři kroky později.
 *
 * ── Pravidlo teď ────────────────────────────────────────────────────────────
 * Tahle služba pustí TYTÉŽ kontroly nasucho, DŘÍV než se cokoli zmrazí, a jen
 * je vrátí k prohlédnutí. Vzor je {@see \MyInvoice\Service\Payroll\PayrollYearCloseService::status()}:
 * čtecí volání vrátí seznam nálezů a samo nic neudělá ani neuloží.
 *
 * NIC z toho neblokuje. Nález je informace pro rozhodnutí („opravdu zahájit?"),
 * ne podmínka — účetní má vidět problém a rozhodnout se sama. Skutečné brány
 * zůstávají tam, kde byly (schválení běhu, příprava plateb); tohle je jen to,
 * aby o nich věděla předem.
 *
 * Kontrola je fail-soft: když se sama nepovede, vrátí nález a pustí dál.
 * Zastavit práci kvůli tomu, že kontrola spadla, by bylo horší než nekontrolovat.
 */
final class PayrollRunReadinessService
{
    /** Kolik konkrétních jmen se u nálezu vypíše, než se přejde na počet. */
    private const MAX_ENTITIES = 25;

    /** Druhy hromadných oprav v `bulk_actions` nálezu ({@see self::bulkActions()}). */
    public const BULK_ACTION_KINDS = [
        'statutory_defaults',
        'declaration_signed',
        'health_insurer',
        'workplace',
        'closure',
    ];

    /**
     * Nálezy, které před zahájením běhu nedávají smysl: odstraní je samo
     * zamknutí vstupů. Hodinová mzda se zmrazí právě při zamknutí, takže výzva
     * „zamkněte nebo obnovte podklady" před založením běhu jen strašila.
     */
    private const RESOLVED_BY_LOCKING = ['hourly_claims_not_materialized'];

    /** Zdroje ověření účtu instituce, které platí za doklad. */
    private const VERIFIED_SOURCE_KINDS = [
        'official_registry',
        'official_document',
        'institution_notice',
        'user_verified',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollRunSnapshotBuilder $snapshotBuilder,
        private readonly PayrollEmployerPolicyRepository $employerPolicies,
        private readonly PayrollPeopleRepository $people,
        private readonly PayrollRunJmhzReadinessProbe $jmhzProbe,
        // První mzdové období firmy. Starší měsíc se nekontroluje, jen odmítne —
        // viz `moduleStartFinding()`.
        private readonly PayrollHistoricalPeriodService $historicalPeriods,
        // Trvale skrytá varování se do předběžné kontroly nepočítají.
        private readonly PayrollWarningSuppressionService $warningSuppressions,
    ) {}

    /**
     * @return array{
     *   period_start:string,
     *   payment_date:string,
     *   office_id:?int,
     *   ready:bool,
     *   findings:list<array{
     *     code:string,
     *     severity:string,
     *     message:string,
     *     remediation_path:?string,
     *     count:int,
     *     entities:list<array{entity_type:string,entity_id:?int}>
     *   }>
     * }
     */
    public function inspect(
        int $supplierId,
        string $periodStart,
        string $paymentDate,
        ?int $officeId = null,
    ): array {
        $findings = [];
        $snapshot = null;

        /*
         * Měsíc PŘED prvním mzdovým obdobím firmy končí hned a jediným nálezem.
         *
         * ── Co bylo špatně ──────────────────────────────────────────────────
         * Kontrola nevěděla nic o tom, odkdy firma vede mzdy v MyÚčtu. Po
         * převodu z předchozího programu, který zpracoval leden až srpen, tak
         * seznam běhů u každého staršího měsíce vypsal dvě stě nálezů — chybí
         * docházka, chybí vstupy, chybí registrace — a účetní je četla jako
         * práci, kterou má udělat. Přitom mzdový běh za takový měsíc NEJDE ani
         * založit: `assertModuleAvailable()` ho odmítne jako období, které
         * předchází aktivaci modulu. Nálezy tedy popisovaly data, která se
         * nikdy počítat nebudou.
         *
         * Starší měsíce patří do počátečních stavů kumulací
         * ({@see \MyInvoice\Service\Payroll\PayrollOpeningBalanceService}), ne
         * do mzdových běhů.
         */
        $moduleStartFinding = $this->moduleStartFinding($supplierId, $periodStart);
        if ($moduleStartFinding !== null) {
            return [
                'period_start' => $periodStart,
                'payment_date' => $paymentDate,
                'office_id' => $officeId,
                'ready' => false,
                'has_findings' => true,
                'findings' => [$moduleStartFinding],
            ];
        }

        // Zaměstnavatelská politika se ověřuje PRVNÍ a zvlášť: bez ní snapshot
        // vůbec nevznikne (builder hodí výjimku), takže by se všechny ostatní
        // nálezy schovaly za jednu hlášku o politice.
        $policyFinding = $this->employerPolicyFinding($supplierId, $periodStart);
        if ($policyFinding !== null) {
            $findings[] = $policyFinding;
        }

        try {
            $snapshot = $this->snapshotBuilder->build(
                $supplierId,
                $periodStart,
                $paymentDate,
                $officeId,
            );
        } catch (PayrollRunSnapshotFixableException $e) {
            // Chybějící identita firmy, předkontace nebo rozpad dimenzí: věc,
            // kterou účetní opraví sama. Hláška to říká i s místem opravy;
            // obecné „obnovte stránku" by ji poslalo jinam.
            $findings[] = self::finding(
                $e->findingCode,
                $e->getMessage(),
                $e->remediationPath,
                1,
                [],
            );
        } catch (\Throwable $e) {
            // Nasucho postavený snímek se zahazuje, takže výjimka tady nic
            // nerozbila — jen se z ní stane nález. Duplicitní hlášku o politice
            // (kterou už máme výš) sem znovu netaháme.
            if ($policyFinding === null) {
                $findings[] = self::finding(
                    'readiness_check_failed',
                    'Předběžnou kontrolu se nepodařilo dokončit. Obnovte stránku. '
                    . 'Pokud se hláška opakuje, požádejte správce aplikace o kontrolu. '
                    . 'Výsledek připravenosti zatím není úplný; po zahájení běhu se '
                    . 'kontroly spustí znovu nad zmrazenými vstupy.',
                    null,
                    1,
                    [],
                );
            }
        }

        if ($snapshot !== null) {
            // Jméno u každého záznamu: 25× „Otevřít místo k opravě" bez jména
            // neříkalo, koho proklik otevře.
            $validations = array_values(array_filter(
                $this->warningSuppressions
                    ->activeSet($supplierId)
                    ->filterValidations($snapshot->validations),
                static fn (PayrollRunValidation $validation): bool =>
                    !in_array($validation->code, self::RESOLVED_BY_LOCKING, true),
            ));
            $employmentIds = [];
            foreach ($validations as $validation) {
                if ($validation->entityType === 'employment' && $validation->entityId !== null) {
                    $employmentIds[$validation->entityId] = $validation->entityId;
                }
            }
            foreach ($this->groupValidations(
                $validations,
                $this->jmhzProbe->employmentLabels($supplierId, array_values($employmentIds)),
            ) as $finding) {
                $findings[] = $finding;
            }
            foreach ($this->institutionAccountFindings(
                $supplierId,
                $paymentDate,
                $snapshot->data,
            ) as $finding) {
                $findings[] = $finding;
            }
            $declarationFinding = self::taxDeclarationFinding($snapshot->data);
            if ($declarationFinding !== null) {
                $findings[] = $declarationFinding;
            }
            /*
             * Nálezy, které se dosud ozvaly až u ZMRAZENÍ měsíčního hlášení.
             * Nepíšou se tu znovu — volá se tatáž cesta, jen nasucho. Bez toho
             * účetní prošla celý měsíc, aby na konci narazila na chybějící
             * zařazení složky nebo na identifikátor od ČSSZ, které mohla
             * doplnit hned první den.
             */
            foreach ($this->jmhzProbe->inspect(
                $supplierId,
                $periodStart,
                $snapshot->data,
            ) as $finding) {
                $findings[] = $finding;
            }
        }

        // Stav prohlášení posuzuje nad snímkem `taxDeclarationFinding()` týmž
        // pravidlem jako výpočet. Katalog mezer ho hodnotí jinak (živé tabulky,
        // překryv intervalů v období), takže by tatáž věc visela dvakrát
        // s jiným počtem. Bez snímku zůstává jediným zdrojem katalog.
        $peopleFinding = $this->personDataGapFinding(
            $supplierId,
            $periodStart,
            $snapshot === null ? [] : ['tax_declaration'],
        );
        if ($peopleFinding !== null) {
            $findings[] = $peopleFinding;
        }
        $pastEndFinding = $this->pastAgreedEndFinding($supplierId, $periodStart);
        if ($pastEndFinding !== null) {
            $findings[] = $pastEndFinding;
        }
        // Jednotný tvar nálezu: co nepochází z katalogu skrytí, skrýt nejde.
        $findings = array_map(
            static function (array $finding): array {
                $bulkActions = self::bulkActions($finding);
                unset($finding['gap_totals']);

                return $finding + [
                    'hideable' => false,
                    'subject_type' => null,
                    'subject_ids' => [],
                    'bulk_actions' => $bulkActions,
                ];
            },
            $findings,
        );

        return [
            'period_start' => $periodStart,
            'payment_date' => $paymentDate,
            'office_id' => $officeId,
            // `ready` znamená „nic nezastaví", ne „nic nechybí". Nález skupiny 1
            // je informace, ne závora — kdyby shazoval `ready`, byla by z něj
            // závora zpátky.
            'ready' => self::blockingFindings($findings) === [],
            'has_findings' => $findings !== [],
            'findings' => $findings,
        ];
    }

    /**
     * Hromadné opravy, které nález nabízí.
     *
     * Nic se tu nepočítá znovu: každá vede na existující dialog v Mzdy →
     * Zaměstnanci → Další funkce (`/payroll/people?bulk=<kind>`), který si
     * kandidáty vybere sám. `count` je jen odhad na tlačítko z nálezu.
     * Schválení vstupů a docházky nabízí karta běhu zvlášť (kroky schválení).
     *
     * @param array<string,mixed> $finding
     * @return list<array{kind:string,count:int}>
     */
    private static function bulkActions(array $finding): array
    {
        $code = (string) ($finding['code'] ?? '');
        $count = max((int) ($finding['count'] ?? 0), (int) ($finding['entity_total'] ?? 0));
        if ($code === 'person_data_gap') {
            /** @var array<string,int> $totals */
            $totals = $finding['gap_totals'] ?? [];
            $actions = [];
            $statutory = max($totals['tax_residence'] ?? 0, $totals['tax_declaration'] ?? 0);
            if ($statutory > 0) {
                $actions[] = ['kind' => 'statutory_defaults', 'count' => $statutory];
            }
            if (($totals['health_insurance'] ?? 0) > 0) {
                $actions[] = ['kind' => 'health_insurer', 'count' => $totals['health_insurance']];
            }

            return $actions;
        }

        return match (true) {
            $code === 'tax_declaration_not_signed_summary',
            $code === 'tax_declaration_evidence_missing' => [['kind' => 'declaration_signed', 'count' => $count]],
            $code === 'employments_past_agreed_end' => [['kind' => 'closure', 'count' => $count]],
            str_starts_with($code, 'jmhz_')
                && JmhzBlockerCatalog::remediation($code)['kind'] === 'workplace'
                => [['kind' => 'workplace', 'count' => $count]],
            default => [],
        };
    }

    /**
     * Osoby běhu, kterým výpočet zastaví daň na „Chybí ověřený stav daňového
     * prohlášení".
     *
     * ── Co bylo špatně ──────────────────────────────────────────────────────
     * Kontrola před během brala stav prohlášení z katalogu mezer v seznamu
     * zaměstnanců: živé tabulky, záznam platný kdykoli v období, všichni se
     * vztahem v měsíci. Výpočet ale posuzuje zákonnou evidenci ZMRAZENOU ve
     * snímku k datu výpočtu daně a jen u osob, které do běhu vstoupily.
     * U simulace února tak kontrola hlásila dvě osoby a zamknutí s výpočtem
     * pak zastavily šestnáct.
     *
     * Teď se ptá nad týmž snímkem, který postaví zamknutí, a týmž pravidlem
     * jako výpočet ({@see PayrollRunStatutoryInputAssembler::taxDeclarationIssue()}).
     * Osobu bez zmrazené evidence nebo bez vztahu výpočet zastaví jiným kódem,
     * proto se tu nepočítá.
     *
     * @param array<string,mixed> $snapshotData
     * @return array<string,mixed>|null
     */
    private static function taxDeclarationFinding(array $snapshotData): ?array
    {
        $affected = [];
        foreach ((array) ($snapshotData['people'] ?? []) as $person) {
            if (!is_array($person)
                || !is_array($person['employee'] ?? null)
                || !is_array($person['statutory_evidence'] ?? null)
                || ($person['employments'] ?? []) === []
            ) {
                continue;
            }
            if (PayrollRunStatutoryInputAssembler::taxDeclarationIssue($person['statutory_evidence']) === null) {
                continue;
            }
            $affected[] = [
                'id' => (int) ($person['employee']['id'] ?? 0),
                'full_name' => (string) ($person['employee']['full_name'] ?? ''),
            ];
        }
        if ($affected === []) {
            return null;
        }
        usort(
            $affected,
            static fn (array $left, array $right): int =>
                [$left['full_name'], $left['id']] <=> [$right['full_name'], $right['id']],
        );
        $named = array_column(array_slice($affected, 0, self::MAX_ENTITIES), 'full_name');
        $hidden = count($affected) - count($named);

        return self::finding(
            'tax_declaration_evidence_missing',
            sprintf(
                'Chybí ověřený stav daňového prohlášení (%d): %s%s. Bez něj výpočet mzdy '
                . 'zastaví daň a běh nepůjde schválit. U každé osoby zaznamenejte, zda '
                . 'prohlášení podepsala, nebo použijte Mzdy → Zaměstnanci → Další funkce '
                . '→ Podepsaná prohlášení hromadně.',
                count($affected),
                implode(', ', $named),
                $hidden > 0 ? sprintf(' a dalších %d', $hidden) : '',
            ),
            '/payroll/people?bulk=declaration_signed',
            count($affected),
            array_map(
                static fn (array $person): array => [
                    'entity_type' => 'employee',
                    'entity_id' => $person['id'],
                    'label' => $person['full_name'],
                    'remediation_path' => self::gapPath($person['id'], 'tax_declaration'),
                ],
                array_slice($affected, 0, self::MAX_ENTITIES),
            ),
        );
    }

    /**
     * Vztahy, které podle sjednaného konce skončily před obdobím běhu, ale
     * pořád jsou aktivní. Do běhu vstoupí jako trvající (nulové hlášení,
     * výzva k mzdě) a z běhu je nikdo neukončí. Ukončení po jednom nikdo
     * nestíhá, proto nález nabízí hromadné ukončení skončených dohod.
     *
     * @return array<string,mixed>|null
     */
    private function pastAgreedEndFinding(int $supplierId, string $periodStart): ?array
    {
        try {
            $statement = $this->db->pdo()->prepare(
                "SELECT employment.id, employee.full_name
                   FROM payroll_employments employment
                   JOIN payroll_employees employee
                     ON employee.supplier_id = employment.supplier_id
                    AND employee.id = employment.employee_id
                  WHERE employment.supplier_id = ?
                    AND employment.status IN ('active', 'suspended')
                    AND employment.end_date IS NOT NULL
                    AND employment.end_date < ?
                  ORDER BY employee.full_name, employment.id"
            );
            $statement->execute([$supplierId, $periodStart]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return null;
        }
        if ($rows === []) {
            return null;
        }

        return self::finding(
            'employments_past_agreed_end',
            sprintf(
                'Pracovní vztahy se sjednaným koncem před obdobím %s jsou pořád aktivní (%d). '
                . 'Ukončete je hromadně v Mzdy → Zaměstnanci → Další funkce → Ukončit skončené dohody.',
                substr($periodStart, 0, 7),
                count($rows),
            ),
            '/payroll/people?bulk=closure',
            count($rows),
            array_map(
                static fn (array $row): array => [
                    'entity_type' => 'employment',
                    'entity_id' => (int) $row['id'],
                    'label' => (string) $row['full_name'],
                ],
                array_slice($rows, 0, self::MAX_ENTITIES),
            ),
        );
    }

    /**
     * @param list<array<string,mixed>> $findings
     * @return list<array<string,mixed>>
     */
    private static function blockingFindings(array $findings): array
    {
        return array_values(array_filter(
            $findings,
            static fn (array $finding): bool =>
                ($finding['impact'] ?? null) === PayrollRunReadinessImpact::IMPACT_BLOCKING,
        ));
    }

    /**
     * Nález doplněný o zařazení („dá se to opravit potom?") a o to, jestli jde
     * o jednorázové nastavení firmy, nebo o měsíční práci.
     *
     * @param list<array{entity_type:string,entity_id:?int,label:?string}> $entities
     * @return array<string,mixed>
     */
    private static function finding(
        string $code,
        string $message,
        ?string $remediationPath,
        int $count,
        array $entities,
    ): array {
        $classification = PayrollRunReadinessImpact::describe($code);

        return [
            'code' => $code,
            'severity' => $classification['severity'],
            'impact' => $classification['impact'],
            'scope' => $classification['scope'],
            'message' => $message,
            'remediation_path' => $remediationPath,
            'count' => $count,
            // Kolik záznamů se nálezu týká CELKEM. `entities` je oříznutý na
            // MAX_ENTITIES, takže jeho délka o počtu nic neříká.
            'entity_total' => $count,
            'entities' => $entities,
            // Nálezy mimo kontroly snímku trvale skrýt nejde.
            'hideable' => false,
            'subject_type' => null,
            'subject_ids' => [],
        ];
    }

    /**
     * Lidé, kterým chybí údaj, bez kterého měsíc neprojde.
     *
     * Je to TÝŽ seznam pravidel, jaký kreslí značku v seznamu zaměstnanců
     * ({@see \MyInvoice\Service\Payroll\People\PayrollPersonDataGapCatalog}) —
     * kontrola před během se s ním tedy
     * nemůže rozejít. Sem patří proto, že chybějící pojišťovna nebo daňová
     * rezidence se dosud ozvaly až u výpočtu nebo u podání, tedy o několik
     * kroků později, než se s tím dalo něco dělat.
     *
     * Neblokuje, jako nic v téhle službě: kdo chce běh zahájit i tak, zahájí ho.
     *
     * @return array{
     *   code:string,severity:string,message:string,remediation_path:?string,
     *   count:int,entities:list<array{entity_type:string,entity_id:?int}>
     * }|null
     */
    /** @param list<string> $excludedGaps klíče mezer, které posuzuje jiný nález */
    private function personDataGapFinding(int $supplierId, string $periodStart, array $excludedGaps = []): ?array
    {
        try {
            // K období běhu, ne k dnešku: dohoda skončená v únoru s údaji
            // zapsanými na únor za únor nic nedluží.
            $gaps = $this->people->blockingDataGapsForPeriod(
                $supplierId,
                $periodStart,
                (new \DateTimeImmutable($periodStart))->modify('last day of this month')->format('Y-m-d'),
                self::MAX_ENTITIES,
                $excludedGaps,
            );
        } catch (\Throwable) {
            // Kontrola je pomocná; její pád nesmí přebít nálezy, kvůli kterým
            // se sem chodí.
            return null;
        }
        $people = $gaps['people'];
        if ($people === []) {
            return null;
        }

        $named = array_map(
            static fn (array $person): string => sprintf(
                '%s (%s)',
                $person['full_name'],
                implode(', ', array_map(self::gapLabel(...), $person['gaps'])),
            ),
            $people,
        );
        // Jmen je nejvýš MAX_ENTITIES, počet je ale celkový — „25×" u firmy,
        // kde údaje chybí dvěma stům lidí, by účetní poslalo špatným směrem.
        $hidden = $gaps['total'] - count($people);

        // `gap_totals` čte jen výběr hromadných oprav; do odpovědi nejde.
        return ['gap_totals' => $gaps['gap_totals'] ?? []] + self::finding(
            'person_data_gap',
            sprintf(
                'Za období %s chybí zákonné údaje u těchto zaměstnanců: %s%s. Bez nich se '
                . 'nedá podat hlášení ani spočítat odvody. Odkaz u osoby otevře místo, kde '
                . 'se údaj doplňuje.',
                substr($periodStart, 0, 7),
                implode('; ', $named),
                $hidden > 0 ? sprintf(' a dalších %d', $hidden) : '',
            ),
            '/payroll/people?filter=blocking_data',
            max($gaps['total'], count($people)),
            array_map(
                static fn (array $person): array => [
                    'entity_type' => 'employee',
                    'entity_id' => $person['id'],
                    'label' => $person['full_name'],
                    'message' => sprintf(
                        '%s: chybí %s.',
                        $person['full_name'],
                        implode(', ', array_map(self::gapLabel(...), $person['gaps'])),
                    ),
                    'gaps' => $person['gaps'],
                    'remediation_path' => self::gapPath($person['id'], $person['gaps'][0] ?? null),
                ],
                $people,
            ),
        );
    }

    /** Název chybějícího údaje do věty nálezu (klíče {@see PayrollPersonDataGapCatalog}). */
    private static function gapLabel(string $key): string
    {
        return match ($key) {
            'employment' => 'pracovní vztah',
            'name' => 'jméno a příjmení',
            'identifier' => 'rodné číslo nebo EČP',
            'residence' => 'adresa trvalého pobytu',
            'health_insurance' => 'zdravotní pojišťovna',
            'tax_residence' => 'daňová rezidence',
            'payout_account' => 'ověřený výplatní účet',
            'contact' => 'kontakt',
            'citizenship' => 'státní občanství',
            'birth_date' => 'datum narození',
            'tax_declaration' => 'stav daňového prohlášení',
            default => $key,
        };
    }

    private static function gapPath(int $personId, ?string $key): string
    {
        $query = ['person' => $personId];
        if ($key !== null) {
            $gap = PayrollPersonDataGapCatalog::describe($key);
            if ($gap['panel'] !== null) $query['panel'] = $gap['panel'];
            if ($gap['field'] !== null) $query['field'] = $gap['field'];
        }

        return '/payroll/people?' . http_build_query($query);
    }

    /**
     * Nález „období předchází prvnímu mzdovému období firmy", nebo `null`.
     *
     * Hranici i pravidlo drží {@see PayrollHistoricalPeriodService} — tentýž
     * předěl odděluje historii i v přehledu docházky a ve výpisu mzdových
     * vstupů, takže tady vlastní kopii mít nesmí.
     *
     * Fail-soft jako zbytek služby: když se stav modulu nepodaří přečíst,
     * kontrola pokračuje po staru. Zamlčet měsíc kvůli chybě čtení by bylo
     * horší než ho zkontrolovat zbytečně.
     *
     * @return array<string,mixed>|null
     */
    private function moduleStartFinding(int $supplierId, string $periodStart): ?array
    {
        $startPeriod = $this->historicalPeriods->startPeriod($supplierId);
        if (!PayrollHistoricalPeriodService::precedesStart($startPeriod, $periodStart)) {
            return null;
        }

        return self::finding(
            'period_before_module_start',
            sprintf(
                'Období %s předchází prvnímu mzdovému období, které vedete v MyÚčtu (%s). '
                . 'Mzdový běh za něj nejde založit. Měsíce zpracované v předchozím programu '
                . 'patří do počátečních stavů kumulací u zaměstnanců, ne do mzdových běhů. '
                . 'Pokud má tenhle měsíc spočítat MyÚčto, změňte první mzdové období '
                . 'v Mzdy → Aktivace mzdové agendy.',
                substr($periodStart, 0, 7),
                (string) $startPeriod,
            ),
            '/payroll',
            1,
            [],
        );
    }

    /**
     * @return array{
     *   code:string,severity:string,message:string,remediation_path:?string,
     *   count:int,entities:list<array{entity_type:string,entity_id:?int}>
     * }|null
     */
    private function employerPolicyFinding(int $supplierId, string $periodStart): ?array
    {
        try {
            if ($this->employerPolicies->findEffective($supplierId, $periodStart) !== null) {
                return null;
            }
            $message = sprintf(
                'Za období %s není účinná žádná zaměstnavatelská mzdová '
                . 'politika. Otevřete Mzdy → Nastavení zaměstnavatele → '
                . 'Mzdové politiky a založte politiku účinnou od %s; bez ní '
                . 'se mzdový běh nedá zahájit.',
                substr($periodStart, 0, 7),
                $periodStart,
            );
        } catch (\Throwable $e) {
            // Překryv dvou politik má vlastní výjimku — je to taky nález, jen
            // s jinou příčinou. Text výjimky říká, co je přesně špatně.
            $message = $e instanceof \MyInvoice\Repository\Payroll\PayrollEmployerPolicyOverlapException
                ? $e->getMessage() . ' Otevřete Mzdové politiky a upravte jejich období účinnosti tak, aby se nepřekrývala.'
                : 'Mzdovou politiku pro období se nepodařilo ověřit. Obnovte stránku. Pokud se hláška opakuje, požádejte správce aplikace o kontrolu.';
        }

        return self::finding(
            'employer_policy_missing',
            $message,
            '/payroll/settings?tab=policies',
            1,
            [],
        );
    }

    /**
     * Nálezy ze snímku sloučené po kódu.
     *
     * Účetní nepotřebuje dvacet řádků „pracovní vztah nemá schválenou
     * docházku" — potřebuje jeden řádek, počet a jména.
     *
     * @param list<PayrollRunValidation> $validations
     * @param array<int,string> $labels popisky vztahů podle id
     * @return list<array{
     *   code:string,severity:string,message:string,remediation_path:?string,
     *   count:int,entities:list<array{entity_type:string,entity_id:?int}>
     * }>
     */
    private function groupValidations(array $validations, array $labels = []): array
    {
        $groups = [];
        foreach ($validations as $validation) {
            if ($validation->severity === 'info') {
                continue;
            }
            $code = $validation->code;
            if (!isset($groups[$code])) {
                /*
                 * Závažnost se PŘEBÍJÍ zařazením, ne přebírá z validace.
                 * Validace hlásí, jak vážně to zní; tady se rozhoduje podle
                 * jediné otázky, která účetní zajímá — dá se to opravit potom?
                 * Skutečné brány (zamknutí, schválení, příprava plateb) zůstávají
                 * tam, kde byly; tohle je jen předběžný přehled.
                 */
                $classification = PayrollRunReadinessImpact::describeValidation(
                    $code,
                    $validation->severity,
                );
                $groups[$code] = [
                    'code' => $code,
                    'severity' => $classification['severity'],
                    'impact' => $classification['impact'],
                    'scope' => $classification['scope'],
                    'message' => $validation->message,
                    'remediation_path' => $validation->remediationPath,
                    'count' => 0,
                    'entity_total' => 0,
                    'entities' => [],
                    // Trvalé skrytí: kód z katalogu a VŠECHNY dotčené subjekty
                    // (`entities` je oříznutý, „skrýt pro všech N" potřebuje celek).
                    'hideable' => PayrollWarningSuppressionCatalog::isHideable(
                        $code,
                        $validation->severity,
                        $validation->requiresOverride,
                    ),
                    'subject_type' => PayrollWarningSuppressionCatalog::subjectType($code),
                    'subject_ids' => [],
                ];
            }
            // Skupina téhož kódu se zařadí podle nejpřísnější validace v ní.
            if ($validation->severity === 'blocker'
                && $groups[$code]['impact'] !== PayrollRunReadinessImpact::IMPACT_BLOCKING
            ) {
                $upgraded = PayrollRunReadinessImpact::describeValidation($code, 'blocker');
                $groups[$code]['impact'] = $upgraded['impact'];
                $groups[$code]['severity'] = $upgraded['severity'];
            }
            if ($groups[$code]['hideable']) {
                foreach (PayrollWarningSuppressionCatalog::subjects(
                    $code,
                    $validation->entityType,
                    $validation->entityId,
                    $validation->subjectIds,
                ) as $subjectId) {
                    $groups[$code]['subject_ids'][] = $subjectId;
                }
            }
            ++$groups[$code]['entity_total'];
            if ($groups[$code]['remediation_path'] !== $validation->remediationPath) {
                $groups[$code]['remediation_path'] = null;
            }
            if ($groups[$code]['message'] !== $validation->message) {
                $groups[$code]['message'] = 'U uvedených osob nebo pracovních vztahů je třeba doplnit podklady. Konkrétní důvod a odkaz k opravě najdete u každého záznamu.';
            }
            ++$groups[$code]['count'];
            if (count($groups[$code]['entities']) < self::MAX_ENTITIES) {
                $groups[$code]['entities'][] = [
                    'entity_type' => $validation->entityType,
                    'entity_id' => $validation->entityId,
                    'label' => $validation->entityType === 'employment' && $validation->entityId !== null
                        ? ($labels[$validation->entityId] ?? null)
                        : null,
                    'message' => $validation->message,
                    'remediation_path' => $validation->remediationPath,
                ];
            }
        }

        return array_values($groups);
    }

    /**
     * Ověřené účty institucí, na které se za tenhle běh bude odvádět.
     *
     * Tohle je nález, který dosud přišel NEJPOZDĚJI ze všech: účet instituce
     * se hledá až při přípravě plateb, tedy po zamknutí, výpočtu, schválení
     * i zaúčtování. Účetní pak stála před hotovým zaúčtovaným během, který
     * nešlo zaplatit. Kontrola je záměrně hrubší než resolver plateb — hlídá
     * jen to, jestli ověřený a účinný účet vůbec existuje; přesnou volbu účtu
     * (a fallbacky u organizačních značek) dělá dál
     * {@see \MyInvoice\Service\Payroll\Payment\PayrollInstitutionPaymentTargetResolver}.
     *
     * @param array<string,mixed> $snapshotData
     * @return list<array{
     *   code:string,severity:string,message:string,remediation_path:?string,
     *   count:int,entities:list<array{entity_type:string,entity_id:?int}>
     * }>
     */
    private function institutionAccountFindings(
        int $supplierId,
        string $paymentDate,
        array $snapshotData,
    ): array {
        if (!$this->db->hasTable('payroll_institution_accounts')) {
            return [];
        }
        $verified = $this->verifiedAccountCodes($supplierId, $paymentDate);
        $missing = [];

        // ČSSZ a finanční úřad se platí u každého běhu se zaměstnanci.
        if (($verified['social_security'] ?? []) === []) {
            $missing[] = 'Správa sociálního zabezpečení';
        }
        if (($verified['tax_office'] ?? []) === []) {
            $missing[] = 'Finanční úřad (záloha na daň ze závislé činnosti)';
        }

        $insurerCodes = PayrollSnapshotHealthInsurers::fromSnapshot($snapshotData);
        $knownInsurers = $verified['health_insurer'] ?? [];
        foreach ($insurerCodes as $code) {
            // U zdravotní pojišťovny kód NENÍ značka, ale identita příjemce —
            // jiný ověřený účet se místo něj použít nesmí, takže se hledá
            // přesná shoda kódu.
            if (!in_array($code, $knownInsurers, true)) {
                $missing[] = sprintf('Zdravotní pojišťovna %s', $code);
            }
        }

        if ($missing === []) {
            return [];
        }

        return [self::finding(
            'institution_account_unverified',
            sprintf(
                'Bez ověřeného účinného účtu je zatím: %s. Spočítat a schválit '
                . 'mzdy jde i tak, ale příprava plateb se o tenhle účet zastaví. '
                . 'Doplňte ho v Mzdy → Nastavení zaměstnavatele → Účty '
                . 'institucí a označte ho jako ověřený.',
                implode(', ', $missing),
            ),
            '/payroll/settings?tab=institutions',
            count($missing),
            array_map(
                static fn (string $name): array => [
                    'entity_type' => 'institution',
                    'entity_id' => null,
                    'label' => $name,
                ],
                $missing,
            ),
        )];
    }

    /**
     * Kódy institucí, které mají k datu výplaty ověřený a účinný účet.
     *
     * @return array<string,list<string>> institution_type => kódy
     */
    private function verifiedAccountCodes(int $supplierId, string $paymentDate): array
    {
        $latestVerification = PayrollInstitutionVerificationWindow::latestAcceptable(
            $paymentDate,
        );
        $placeholders = implode(',', array_fill(0, count(self::VERIFIED_SOURCE_KINDS), '?'));
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT institution.institution_type,
                    institution.institution_code
               FROM payroll_institution_accounts account
               JOIN payroll_institutions institution
                 ON institution.id = account.institution_id
                AND institution.supplier_id = account.supplier_id
              WHERE account.supplier_id = ?
                AND account.currency_code = ?
                AND account.valid_from <= ?
                AND (account.valid_to IS NULL OR account.valid_to >= ?)
                AND account.verified_by IS NOT NULL
                AND account.verified_on <= ?
                AND account.source_kind IN (' . $placeholders . ')'
        );
        $stmt->execute(array_merge(
            [$supplierId, 'CZK', $paymentDate, $paymentDate, $latestVerification],
            self::VERIFIED_SOURCE_KINDS,
        ));

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(string) $row['institution_type']][] =
                (string) $row['institution_code'];
        }

        return $result;
    }

}
