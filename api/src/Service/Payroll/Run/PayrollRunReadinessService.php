<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollDeadlineOverviewRepository;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository;
use MyInvoice\Repository\Payroll\PayrollPeopleRepository;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementStatute;
use MyInvoice\Repository\Payroll\PayrollRulesetRepository;
use MyInvoice\Service\Payroll\Payment\PayrollInstitutionVerificationWindow;
use MyInvoice\Service\Payroll\PayrollEmploymentBulkClosure;
use MyInvoice\Service\Payroll\PayrollHistoricalPeriodService;
use MyInvoice\Service\Payroll\PayrollOpeningBalanceService;
use MyInvoice\Service\Payroll\People\PayrollPersonDataGapCatalog;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetRegistry;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetYearOutlook;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzBlockerCatalog;
use PDO;
use Psr\Clock\ClockInterface;

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
        'annual_settlement_request',
    ];

    /**
     * Nálezy, které před zahájením běhu nedávají smysl: odstraní je samo
     * zamknutí vstupů. Hodinová mzda se zmrazí právě při zamknutí, takže výzva
     * „zamkněte nebo obnovte podklady" před založením běhu jen strašila.
     */
    private const RESOLVED_BY_LOCKING = ['hourly_claims_not_materialized'];

    /**
     * Mezery katalogu osob, které se SE SNÍMKEM posuzují pravidlem výpočtu
     * ({@see self::taxDeclarationFinding()}, {@see self::statutoryInputFinding()}).
     */
    private const GAPS_JUDGED_BY_SNAPSHOT = ['tax_declaration', 'tax_residence', 'health_insurance'];

    /**
     * Kódy zákonného vstupu, které hlásí jiný nález: stav prohlášení
     * `taxDeclarationFinding()` a chybějící podmínky vztahu validace snímku
     * (`missing_effective_employment_term`).
     */
    private const STATUTORY_ISSUES_REPORTED_ELSEWHERE = [
        'tax_declaration_evidence_missing',
        'tax_declaration_evidence_unverified',
        'employment_term_missing',
    ];

    /** Sekce zákonné evidence, které doplní hromadné výchozí údaje. */
    private const STATUTORY_DEFAULT_FIELDS = [
        'statutory.tax_residences',
        'statutory.social_jurisdictions',
        'statutory.social_discount_claims',
    ];

    /** Kódy zákonného vstupu, které vyřeší hromadné přiřazení pojišťovny. */
    private const HEALTH_INSURER_ISSUE_CODES = [
        'health_coverage_evidence_missing',
        'health_insurer_evidence_unverified',
        'health_insurer_snapshot_unverified',
    ];

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
        // Žádosti o roční zúčtování, které čekají na provedení — tentýž dotaz
        // jako termín „provést roční zúčtování" v přehledu lhůt.
        private readonly PayrollDeadlineOverviewRepository $deadlines,
        // Dnešek pro upozornění, že období běhu ještě neskončilo nebo nezačalo.
        private readonly ClockInterface $clock,
        // Skončené dohody: stejné pravidlo sjednaného konce jako hromadné ukončení.
        private readonly PayrollEmploymentBulkClosure $bulkClosure,
        // Nulové počáteční úhrny, které doplní založení běhu.
        private readonly PayrollOpeningBalanceService $openingBalances,
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

        // Rok bez legislativní sady: běh nejde založit a jiné nálezy nemají
        // smysl (snímek by se nepostavil a skončil obecným „obnovte stránku").
        try {
            $this->snapshotBuilder->assertRulesetCoverage($periodStart);
        } catch (\DomainException $e) {
            return [
                'period_start' => $periodStart,
                'payment_date' => $paymentDate,
                'office_id' => $officeId,
                'ready' => false,
                'has_findings' => true,
                'findings' => [self::finding('ruleset_year_unavailable', $e->getMessage(), '/payroll/rulesets', 1, [])],
            ];
        }

        $timingFinding = $this->periodTimingFinding($periodStart);
        if ($timingFinding !== null) {
            $findings[] = $timingFinding;
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
            $validations = $this->reportedValidations($supplierId, $snapshot->validations);
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
                $findings[] = self::withPeopleList(
                    $finding,
                    $this->validationEmployeeIds($supplierId, $validations, $finding['code']),
                    $periodStart,
                    $paymentDate,
                );
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
            $settlementFinding = $this->annualSettlementPendingFinding(
                $supplierId,
                $periodStart,
                $snapshot->data,
            );
            if ($settlementFinding !== null) {
                $findings[] = $settlementFinding;
            }
            $statutoryFinding = self::statutoryInputFinding(
                $snapshot->data,
                $this->statutoryIssuesBeforeRun($supplierId, $periodStart, $snapshot->data),
            );
            if ($statutoryFinding !== null) {
                $findings[] = $statutoryFinding;
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

        // Stav prohlášení, daňovou rezidenci a pojišťovnu posuzují nad snímkem
        // `taxDeclarationFinding()` a `statutoryInputFinding()` týmž pravidlem
        // jako výpočet. Katalog mezer je hodnotí jinak (živé tabulky, překryv
        // intervalů v období), takže by tatáž věc visela dvakrát s jiným počtem
        // a jako „doplní se kdykoli", ačkoli schválení zastaví. Bez snímku
        // zůstává jediným zdrojem katalog.
        $peopleFinding = $this->personDataGapFinding(
            $supplierId,
            $periodStart,
            $snapshot === null ? [] : self::GAPS_JUDGED_BY_SNAPSHOT,
        );
        if ($peopleFinding !== null) {
            $findings[] = $peopleFinding;
        }
        $pastEndFinding = $this->pastAgreedEndFinding($supplierId, $periodStart);
        if ($pastEndFinding !== null) {
            $findings[] = $pastEndFinding;
        }
        $minimumWageFinding = $this->minimumWageFinding($supplierId, $periodStart);
        if ($minimumWageFinding !== null) {
            $findings[] = $minimumWageFinding;
        }
        $pendingValuesFinding = $this->pendingYearValuesFinding($periodStart);
        if ($pendingValuesFinding !== null) {
            $findings[] = $pendingValuesFinding;
        }
        // Jednotný tvar nálezu: co nepochází z katalogu skrytí, skrýt nejde.
        $findings = array_map(
            static function (array $finding): array {
                $bulkActions = self::bulkActions($finding);
                $requiresOverride = ($finding['requires_override'] ?? false) === true;
                unset($finding['gap_totals'], $finding['bulk_employee_ids'], $finding['bulk_totals'], $finding['requires_override']);

                return $finding + [
                    'hideable' => false,
                    'subject_type' => null,
                    'subject_ids' => [],
                    'requires_override' => $requiresOverride,
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
     * Období běhu ještě neskončilo, nebo ani nezačalo.
     *
     * ── Co bylo špatně ──────────────────────────────────────────────────────
     * Běh šel bez jediné zmínky založit, spočítat i schválit za měsíc, který
     * teprve přijde. Zastavila se až příprava měsíčního hlášení, a to hláškou
     * katalogu kontrol, ze které účetní nepoznala, že jen předběhla kalendář.
     *
     * Není to závora: výplata před koncem měsíce je legitimní (prosincové
     * mzdy před svátky, zálohová výplata). Kontrola jen říká, s čím počítat:
     * podklady se do konce měsíce ještě mohou změnit a měsíční hlášení se
     * podává až za uplynulý kalendářní měsíc. U měsíce, který ani nezačal, je
     * výpočet celý z plánu, proto silnější zařazení (oprava přes revizi).
     *
     * @return array<string,mixed>|null
     */
    private function periodTimingFinding(string $periodStart): ?array
    {
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $periodStart);
        if ($start === false) {
            return null;
        }
        $today = $this->clock->now()->format('Y-m-d');
        $end = $start->modify('last day of this month');
        if ($end->format('Y-m-d') < $today) {
            return null;
        }
        $period = $start->format('m/Y');
        $reportsFrom = $end->modify('+1 day')->format('j. n. Y');
        if ($periodStart > $today) {
            return self::finding(
                'period_not_started',
                sprintf(
                    'Období %s ještě nezačalo (začíná %s). Mzdy se spočítají jen z plánu: docházka,'
                    . ' nepřítomnosti, mzdové vstupy i pracovní vztahy se do konce měsíce ještě změní'
                    . ' a každá změna po schválení běhu znamená opravnou revizi. Měsíční hlášení'
                    . ' a přehledy pojistného půjde připravit až po skončení měsíce, nejdříve %s.'
                    . ' Jde-li o výplatu předem, pokračujte; jinak s během počkejte.',
                    $period,
                    $start->format('j. n. Y'),
                    $reportsFrom,
                ),
                null,
                1,
                [],
            );
        }

        return self::finding(
            'period_not_finished',
            sprintf(
                'Období %s ještě neskončilo (končí %s). Výplata před koncem měsíce je v pořádku,'
                . ' jen počítejte s tím, že docházka, nepřítomnosti a mzdové vstupy se do konce měsíce'
                . ' mohou změnit; změna po schválení běhu znamená opravnou revizi. Měsíční hlášení'
                . ' a přehledy pojistného půjde připravit až po skončení měsíce, nejdříve %s.',
                $period,
                $end->format('j. n. Y'),
                $reportsFrom,
            ),
            null,
            1,
            [],
        );
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
        if ($code === 'statutory_calculation_manual_review') {
            /** @var array<string,int> $totals */
            $totals = $finding['bulk_totals'] ?? [];
            $actions = [];
            foreach (['statutory_defaults', 'health_insurer'] as $kind) {
                if (($totals[$kind] ?? 0) > 0) {
                    $actions[] = ['kind' => $kind, 'count' => $totals[$kind]];
                }
            }

            return $actions;
        }
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

        /*
         * Podpis prohlášení je fakt o konkrétních lidech. Bez výčtu otevřel
         * dialog všechny osoby roku a předvybral i ty, kterým má evidence
         * výslovně „Nepodepsáno" — tlačítko „(16)" tak zapsalo podpis 47 lidem.
         */
        $declarationIds = $code === 'tax_declaration_evidence_missing'
            ? array_values(array_map('intval', (array) ($finding['bulk_employee_ids'] ?? [])))
            : array_values(array_map('intval', (array) ($finding['subject_ids'] ?? [])));

        /*
         * Tlačítko musí slibovat tolik osob, kolik dialog dostane. Souhrn
         * nepodepsaných prohlášení je jediná validace běhu s výčtem osob
         * v `subject_ids`: tlačítko ukazovalo „(1)" a dialog dostal 34 osob.
         */
        if ($declarationIds !== []) {
            $count = count($declarationIds);
        }

        return match (true) {
            $code === 'tax_declaration_not_signed_summary',
            $code === 'tax_declaration_evidence_missing' => [[
                'kind' => 'declaration_signed',
                // Souhrnné varování je JEDNA validace za všechny osoby; počet na
                // tlačítku musí odpovídat tomu, komu dialog podpis zapíše.
                'count' => $declarationIds === [] ? $count : count($declarationIds),
                ...($declarationIds === [] ? [] : ['employee_ids' => $declarationIds]),
            ]],
            $code === 'employments_past_agreed_end' => [['kind' => 'closure', 'count' => $count]],
            // Hromadné „nepožádal" je výběr na stránce Roční zúčtování, ne dialog
            // v seznamu zaměstnanců; klient tam vede s rokem a zúžením.
            $code === 'jmhz_annual_request_status_unresolved'
                => [['kind' => 'annual_settlement_request', 'count' => $count]],
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

        return [...self::finding(
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
        ), 'bulk_employee_ids' => array_column($affected, 'id')];
    }

    /**
     * Osoby běhu, které o roční zúčtování za minulý rok požádaly, ale
     * zúčtování ještě neproběhlo.
     *
     * ── Co bylo špatně ──────────────────────────────────────────────────────
     * Přeplatek ze zúčtování vyplácí běh za leden až březen
     * ({@see AnnualSettlementStatute::payoutWindowTaxYear()}). Po březnu ho
     * do žádného běhu modul nepustí. Nevyřízenou žádost ukázal jen přehled
     * lhůt; kontrola před během o ní mlčela, takže březnová mzda šla
     * schválit bez přeplatku a zaměstnanec o něj tiše přišel.
     *
     * ── Pravidlo ────────────────────────────────────────────────────────────
     * § 38ch odst. 4 ZDP (ve znění do 31. 12. 2026, pro rok 2026 podle čl. VII
     * bodu 6 zák. č. 360/2025 Sb.): plátce zúčtování provede nejpozději do
     * 31. března; § 38ch odst. 5: přeplatek vrátí „nejpozději při zúčtování
     * mzdy za březen". V lednu a únoru je to informace (vyplatit jde ještě
     * v dalším měsíci), v březnu varování: po schválení běhu jde přeplatek
     * vyplatit už jen opravnou revizí. Mzdu ostatních to nezastaví.
     *
     * Kdo musí podat přiznání, tomu se zúčtování neprovádí; vynechává ho
     * týž dotaz jako přehled lhůt
     * ({@see PayrollDeadlineOverviewRepository::annualSettlementsToPerform()}).
     *
     * @param array<string,mixed> $snapshotData
     * @return array<string,mixed>|null
     */
    private function annualSettlementPendingFinding(
        int $supplierId,
        string $periodStart,
        array $snapshotData,
    ): ?array {
        $taxYear = AnnualSettlementStatute::payoutWindowTaxYear($periodStart);
        if ($taxYear === null) {
            return null;
        }
        $names = [];
        foreach ((array) ($snapshotData['people'] ?? []) as $person) {
            $employee = is_array($person) ? ($person['employee'] ?? null) : null;
            if (is_array($employee) && is_int($employee['id'] ?? null)) {
                $names[$employee['id']] = (string) ($employee['full_name'] ?? '');
            }
        }
        if ($names === []) {
            return null;
        }
        $pending = [];
        foreach ($this->deadlines->annualSettlementsToPerform($supplierId, [$taxYear]) as $row) {
            if (isset($names[$row['employee_id']])) {
                $pending[$row['employee_id']] = $names[$row['employee_id']] !== ''
                    ? $names[$row['employee_id']]
                    : $row['full_name'];
            }
        }
        if ($pending === []) {
            return null;
        }
        ksort($pending, SORT_NUMERIC);

        $lastPayout = $periodStart === AnnualSettlementStatute::payoutPeriodStart($taxYear)->format('Y-m-d');
        $deadline = AnnualSettlementStatute::settlementDeadline($taxYear)->format('j. n. Y');
        $labels = array_values($pending);
        $named = implode(', ', array_slice($labels, 0, 10)) . (count($labels) > 10 ? ' a další' : '');
        $message = sprintf(
            'O roční zúčtování za rok %d požádal(a), ale zúčtování ještě neproběhlo: %s. '
            . 'Proveďte ho v Mzdy → Roční zúčtování nejpozději do %s; přeplatek se vyplatí '
            . 'v nejbližším běhu po provedení, nejpozději ve mzdě za březen (§ 38ch odst. 4 a 5 ZDP). ',
            $taxYear,
            $named,
            $deadline,
        ) . ($lastPayout
            ? 'Tohle je poslední běh, ve kterém se přeplatek vyplatí. Proveďte zúčtování před '
                . 'zamknutím vstupů; po schválení běhu ho vyplatí už jen opravná revize. '
                . 'Mzdu ostatních to nezastaví.'
            : 'Mzdový běh tím omezený není.');
        $entities = [];
        foreach (array_slice($pending, 0, self::MAX_ENTITIES, true) as $employeeId => $label) {
            $entities[] = [
                'entity_type' => 'employee',
                'entity_id' => $employeeId,
                'label' => $label,
                'remediation_path' => '/payroll/annual-settlement?'
                    . http_build_query(['year' => $taxYear, 'person' => $employeeId]),
            ];
        }

        return self::finding(
            $lastPayout ? 'annual_settlement_pending_last_payout' : 'annual_settlement_pending',
            $message,
            '/payroll/annual-settlement?' . http_build_query(['year' => $taxYear, 'state' => 'unsettled']),
            count($pending),
            $entities,
        );
    }

    /**
     * Problémy zákonných vstupů tak, jak je uvidí výpočet založeného běhu.
     *
     * Založení běhu doplní nulové počáteční úhrny tam, kde je nula prokazatelná
     * ({@see PayrollOpeningBalanceService::provableZeroOpeningEmployeeIds()}).
     * Kontrola před během je proto u takových osob nehlásí: dřív byly před
     * během blokací a po výpočtu zmizely. Ostatní chybějící úhrny zůstávají.
     *
     * @param array<string,mixed> $snapshotData
     * @return list<PayrollRunStatutoryInputIssue>|null null = sestavení selhalo
     */
    private function statutoryIssuesBeforeRun(int $supplierId, string $periodStart, array $snapshotData): ?array
    {
        try {
            $issues = (new PayrollRunStatutoryInputAssembler())->assemble($snapshotData)->issues;
        } catch (\Throwable) {
            return null;
        }
        $missing = [];
        foreach ($issues as $issue) {
            if ($issue->code === 'annual_accumulator_missing'
                && preg_match('/^employee:(\d+)$/D', (string) $issue->personReference, $match) === 1
            ) {
                $missing[(int) $match[1]] = (int) $match[1];
            }
        }
        if ($missing === []) {
            return array_values($issues);
        }
        $seeded = array_fill_keys(
            $this->openingBalances->provableZeroOpeningEmployeeIds($supplierId, $periodStart, array_values($missing)),
            true,
        );

        return array_values(array_filter(
            $issues,
            static fn (PayrollRunStatutoryInputIssue $issue): bool => !(
                $issue->code === 'annual_accumulator_missing'
                && preg_match('/^employee:(\d+)$/D', (string) $issue->personReference, $match) === 1
                && isset($seeded[(int) $match[1]])
            ),
        ));
    }

    /**
     * Osoby běhu, které zákonný výpočet zastaví ruční kontrolou (chybí
     * daňová rezidence, platné zdravotní pojištění, ověřená pojišťovna …).
     *
     * ── Co bylo špatně ──────────────────────────────────────────────────────
     * Chybějící rezidenci a pojišťovnu hlásila kontrola jen v katalogu mezer
     * osob jako „doplní se kdykoli". Výpočet pak u týchž lidí skončil
     * blokací „statutory_calculation_manual_review" a běh nešel schválit.
     * Agentura s padesáti brigádníky se o tom dozvěděla až po zamknutí.
     *
     * Teď se pouští TÝŽ sestavovač zákonných vstupů jako výpočet
     * ({@see PayrollRunStatutoryInputAssembler}) nad týmž snímkem a text
     * i odkaz se skládají TÝMŽ formátem jako validace běhu
     * ({@see PayrollRunIssueGuidance::describe()}), takže nález má stejný
     * počet řádků, jaký pak výpočet uloží. Stav prohlášení zůstává
     * v `taxDeclarationFinding()` (vlastní hromadná oprava) a chybějící
     * podmínky vztahu ve validaci snímku, proto se tu nepočítají podruhé.
     *
     * @param array<string,mixed> $snapshotData
     * @return array<string,mixed>|null
     */
    private static function statutoryInputFinding(array $snapshotData, ?array $issues): ?array
    {
        if ($issues === null) {
            return null;
        }
        $names = [];
        foreach ((array) ($snapshotData['people'] ?? []) as $person) {
            if (is_array($person) && is_array($person['employee'] ?? null)) {
                $names[(int) ($person['employee']['id'] ?? 0)] = (string) ($person['employee']['full_name'] ?? '');
            }
        }
        $details = [];
        $statutoryPeople = [];
        $insurerPeople = [];
        foreach ($issues as $issue) {
            if ($issue->isGlobal() || in_array($issue->code, self::STATUTORY_ISSUES_REPORTED_ELSEWHERE, true)) {
                continue;
            }
            $detail = PayrollRunIssueGuidance::describe($issue->toIssueString());
            $key = $detail['entity_type'] . ':' . $detail['entity_id'] . ':' . $detail['remediation_path'] . ':' . $detail['message'];
            if (isset($details[$key])) {
                continue;
            }
            $details[$key] = $detail;
            if ($detail['entity_type'] !== 'employee' || $detail['entity_id'] === null) {
                continue;
            }
            foreach (self::STATUTORY_DEFAULT_FIELDS as $field) {
                if (str_contains((string) $detail['remediation_path'], 'field=' . rawurlencode($field))
                    || str_contains((string) $detail['remediation_path'], 'field=' . $field)
                ) {
                    $statutoryPeople[$detail['entity_id']] = true;
                }
            }
            if (in_array($issue->code, self::HEALTH_INSURER_ISSUE_CODES, true)) {
                $insurerPeople[$detail['entity_id']] = true;
            }
        }
        if ($details === []) {
            return null;
        }
        $details = array_values($details);
        usort(
            $details,
            static fn (array $left, array $right): int =>
                [$names[$left['entity_id']] ?? '', $left['entity_id'], $left['message']]
                <=> [$names[$right['entity_id']] ?? '', $right['entity_id'], $right['message']],
        );
        /*
         * JEDNO číslo pro nadpis nálezu, větu i tlačítka hromadné opravy:
         * počet OSOB. Dřív měla osoba se dvěma chybějícími údaji dva řádky,
         * nadpis nesl počet řádků, věta počet osob a dialog hromadné opravy
         * zase osoby, takže „3×" vedle „u 2 osob" vypadalo jako chyba součtu.
         * Osoba je proto v nálezu jednou, se všemi svými větami; odkaz vede
         * na společné místo opravy. Záznam, který na osobu nemíří, zůstává
         * sám za sebe.
         */
        $folded = [];
        foreach ($details as $index => $detail) {
            $key = $detail['entity_type'] === 'employee' && $detail['entity_id'] !== null
                ? 'employee:' . $detail['entity_id']
                : 'other:' . $index;
            if (!isset($folded[$key])) {
                $folded[$key] = $detail;
                continue;
            }
            if (!str_contains((string) $folded[$key]['message'], (string) $detail['message'])) {
                $folded[$key]['message'] .= ' ' . $detail['message'];
            }
            $folded[$key]['remediation_path'] = self::sharedPersonPath(
                $folded[$key]['remediation_path'],
                $detail['remediation_path'],
            );
        }
        $details = array_values($folded);
        $people = [];
        foreach ($details as $detail) {
            if ($detail['entity_type'] === 'employee' && $detail['entity_id'] !== null) {
                $people[$detail['entity_id']] = $names[$detail['entity_id']] ?? (string) $detail['entity_id'];
            }
        }
        $named = array_slice(array_values($people), 0, self::MAX_ENTITIES);
        $hidden = count($people) - count($named);
        $subjects = count($details);
        // Hromadnou opravu radí jen tam, kde ji má kdo využít.
        $bulkHints = array_values(array_filter([
            $statutoryPeople !== [] ? 'daňovou rezidenci doplní Mzdy → Zaměstnanci → Další funkce → Doplnit výchozí zákonné údaje' : null,
            $insurerPeople !== [] ? 'pojišťovnu Mzdy → Zaměstnanci → Další funkce → Přiřadit zdravotní pojišťovnu hromadně' : null,
        ]));

        return [...self::finding(
            'statutory_calculation_manual_review',
            sprintf(
                'Zákonný výpočet zastaví %s chybějící nebo neověřené údaje v zákonné '
                . 'evidenci: %s%s. Mzdu spočítat půjde jen zčásti a běh nepůjde schválit. '
                . 'Odkaz u každé osoby otevře místo opravy%s.',
                count($people) === 1 ? 'u 1 osoby' : sprintf('u %d osob', count($people)),
                implode(', ', $named),
                $hidden > 0 ? sprintf(' a dalších %d', $hidden) : '',
                $bulkHints === [] ? '' : '; ' . implode(', ', $bulkHints),
            ),
            '/payroll/people?filter=blocking_data',
            $subjects,
            array_map(
                static fn (array $detail): array => [
                    'entity_type' => $detail['entity_type'],
                    'entity_id' => $detail['entity_id'],
                    'label' => $detail['entity_type'] === 'employee' && $detail['entity_id'] !== null
                        ? ($names[$detail['entity_id']] ?? null)
                        : null,
                    'message' => $detail['message'],
                    'remediation_path' => $detail['remediation_path'],
                ],
                array_slice($details, 0, self::MAX_ENTITIES),
            ),
        ), 'bulk_totals' => [
            'statutory_defaults' => count($statutoryPeople),
            'health_insurer' => count($insurerPeople),
        ]];
    }

    /**
     * Odkaz pro osobu s víc chybějícími údaji: stejné místo zůstává, různá
     * pole téhož panelu vedou na panel a různé panely na kartu osoby.
     */
    private static function sharedPersonPath(?string $left, ?string $right): ?string
    {
        if ($left === null || $right === null || $left === $right) {
            return $left ?? $right;
        }
        $leftQuery = [];
        $rightQuery = [];
        parse_str((string) parse_url($left, PHP_URL_QUERY), $leftQuery);
        parse_str((string) parse_url($right, PHP_URL_QUERY), $rightQuery);
        $path = (string) parse_url($left, PHP_URL_PATH);
        if ($path !== (string) parse_url($right, PHP_URL_PATH)) {
            return $left;
        }
        $shared = [];
        foreach (['person', 'employment', 'panel'] as $key) {
            if (isset($leftQuery[$key]) && ($leftQuery[$key] === ($rightQuery[$key] ?? null))) {
                $shared[$key] = $leftQuery[$key];
            }
        }

        return $shared === [] ? $path : $path . '?' . http_build_query($shared);
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
            // Totéž pravidlo jako dialog hromadného ukončení, aby počet v nálezu
            // a počet předvybraných vztahů v dialogu byly jedno číslo.
            $rows = $this->bulkClosure->expiredBeforePeriod(
                $supplierId,
                $periodStart,
                $this->clock->now()->format('Y-m-d'),
            );
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
                substr($periodStart, 5, 2) . '/' . substr($periodStart, 0, 4),
                count($rows),
            ),
            '/payroll/people?bulk=closure&period=' . substr($periodStart, 0, 7),
            count($rows),
            array_map(
                static fn (array $row): array => [
                    'entity_type' => 'employment',
                    'entity_id' => $row['employment_id'],
                    'label' => (string) $row['full_name'],
                ],
                array_slice($rows, 0, self::MAX_ENTITIES),
            ),
        );
    }

    /**
     * Pracovní poměry s měsíční mzdou sjednanou pod minimální mzdou období.
     *
     * § 111 odst. 2 ZP: mzda nesmí být nižší než minimální mzda. Měsíční
     * minimální mzda (pravidlo `minimum_wage.monthly_40h_week`, pro rok 2027
     * 24 900 Kč podle nařízení vlády č. 164/2026 Sb. a sdělení MPSV
     * č. 171/2026 Sb.) platí pro stanovenou týdenní pracovní dobu 40 hodin,
     * u kratší pracovní doby poměrně (§ 80 ZP). Podíl se bere jako menší
     * z úvazku a sjednané týdenní doby proti 40 hodinám, takže při kratší
     * stanovené době (§ 79 odst. 2) nález spíš nevznikne, než aby vznikl
     * navíc. Měsíční mzda se do běhu zadává jako vstup a její doplatek do
     * minima výpočet nedopočítává (hodinová mzda ho má vlastní), proto se
     * na sjednanou mzdu upozorní před během, typicky v lednu po zvýšení
     * minima.
     *
     * @return array<string,mixed>|null
     */
    private function minimumWageFinding(int $supplierId, string $periodStart): ?array
    {
        try {
            $minimum = (int) (new PayrollRulesetRegistry(new PayrollRulesetRepository($this->db)))
                ->provider()
                ->forCalculation(PayrollRulesetDomain::EmploymentThresholds, $periodStart)
                ->parameter('minimum_wage.monthly_40h_week')
                ->value;
            $periodEnd = (new \DateTimeImmutable($periodStart))->modify('last day of this month')->format('Y-m-d');
            $statement = $this->db->pdo()->prepare(
                "SELECT employment.id, employee.full_name, terms.monthly_gross_minor,
                        terms.weekly_hours, terms.workload_basis_points
                   FROM payroll_employments employment
                   JOIN payroll_employees employee
                     ON employee.supplier_id = employment.supplier_id
                    AND employee.id = employment.employee_id
                   JOIN payroll_employment_terms terms
                     ON terms.supplier_id = employment.supplier_id
                    AND terms.employment_id = employment.id
                  WHERE employment.supplier_id = ?
                    AND employment.status IN ('active', 'suspended')
                    AND employment.relation_type IN ('employment', 'small_scale_employment')
                    AND terms.effective_from <= ?
                    AND (terms.effective_to IS NULL OR terms.effective_to >= ?)
                    AND COALESCE(terms.remuneration_type, 'monthly') = 'monthly'
                    AND terms.monthly_gross_minor > 0
                  ORDER BY employee.full_name, employment.id, terms.effective_from"
            );
            $statement->execute([$supplierId, $periodEnd, $periodStart]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return null;
        }
        if ($minimum <= 0) {
            return null;
        }
        $below = [];
        foreach ($rows as $row) {
            // Podíl jako zlomek: úvazek v desetitisícinách, týdenní doba v setinách hodiny.
            $workload = (int) ($row['workload_basis_points'] ?? 10_000);
            [$numerator, $denominator] = [$workload > 0 ? $workload : 10_000, 10_000];
            if ($row['weekly_hours'] !== null) {
                $centiHours = (int) round(((float) $row['weekly_hours']) * 100);
                if ($centiHours > 0 && $centiHours * $denominator < $numerator * 4_000) {
                    [$numerator, $denominator] = [$centiHours, 4_000];
                }
            }
            if ($numerator > $denominator) {
                [$numerator, $denominator] = [1, 1];
            }
            if ((int) $row['monthly_gross_minor'] * $denominator < $minimum * $numerator) {
                $below[(int) $row['id']] = (string) $row['full_name'];
            }
        }
        if ($below === []) {
            return null;
        }
        $firstEmploymentId = array_key_first($below);

        return self::finding(
            'monthly_wage_below_minimum',
            sprintf(
                'Sjednaná měsíční mzda je nižší než minimální mzda pro období %s (%s Kč měsíčně'
                . ' při 40 hodinách týdně, u kratší pracovní doby poměrně) u %d vztahů. Mzda'
                . ' nesmí být nižší než minimální mzda (§ 111 zákoníku práce) a výpočet měsíční'
                . ' mzdy doplatek do minima nedopočítává. Upravte mzdu v podmínkách vztahu,'
                . ' nebo zadejte doplatek do minimální mzdy jako mzdový vstup.',
                substr($periodStart, 5, 2) . '/' . substr($periodStart, 0, 4),
                number_format(intdiv($minimum, 100), 0, ',', ' '),
                count($below),
            ),
            count($below) === 1
                ? '/payroll/people?employment=' . $firstEmploymentId . '&panel=employment_terms&field=monthly_gross_minor'
                : '/payroll/people',
            count($below),
            array_map(
                static fn (int $id, string $name): array => [
                    'entity_type' => 'employment',
                    'entity_id' => $id,
                    'label' => $name,
                ],
                array_slice(array_keys($below), 0, self::MAX_ENTITIES),
                array_slice(array_values($below), 0, self::MAX_ENTITIES),
            ),
        );
    }

    /** Agendy bez sady na rok, o kterých má mzdová účetní vědět už před během. */
    private const PENDING_AGENDA_LABELS = [
        'enforcement_deductions' => 'exekuční srážky (nezabavitelné částky)',
        'travel_allowances' => 'cestovní náhrady',
    ];

    /**
     * Hodnoty roku, které ještě nevyšly ve Sbírce a aplikace je neodhaduje.
     *
     * Kontrola za leden nového roku o nich mlčela: jádro výpočtu sadu mělo,
     * takže vše vypadalo hotové, a na nezabavitelné částky nebo stravenkový
     * paušál se narazilo až u konkrétní osoby. Čte se tentýž výhled jako na
     * stránce legislativních pravidel ({@see PayrollRulesetYearOutlook}).
     * Jen informace: mzdy se spočítají, zastaví se jen to, co na chybějící
     * hodnotě stojí.
     *
     * @return array<string,mixed>|null
     */
    private function pendingYearValuesFinding(string $periodStart): ?array
    {
        try {
            $provider = (new PayrollRulesetRegistry(new PayrollRulesetRepository($this->db)))->provider();
            $year = (int) substr($periodStart, 0, 4);
            $agendas = array_values(array_intersect(
                array_keys(self::PENDING_AGENDA_LABELS),
                PayrollRulesetYearOutlook::agendaGaps($provider, $year),
            ));
            $parameters = PayrollRulesetYearOutlook::pendingParameters($provider, $year);
        } catch (\Throwable) {
            return null;
        }
        if ($agendas === [] && $parameters === []) {
            return null;
        }
        $names = [
            ...array_map(static fn (string $domain): string => self::PENDING_AGENDA_LABELS[$domain], $agendas),
            ...array_map(
                static fn (array $parameter): string => mb_strtolower(mb_substr((string) ($parameter['label'] ?? $parameter['key']), 0, 1))
                    . mb_substr((string) ($parameter['label'] ?? $parameter['key']), 1),
                $parameters,
            ),
        ];

        return self::finding(
            'ruleset_year_values_pending',
            sprintf(
                'Pro rok %d ještě nejsou vyhlášené všechny hodnoty: %s. Aplikace je neodhaduje.'
                . ' Mzdy se spočítají, jen to, co na chybějící hodnotě stojí (například srážka z exekuce,'
                . ' cestovní náhrada nebo osvobození příspěvku na stravování), výpočet u dotčené osoby'
                . ' zastaví a řekne to. Hodnoty přijdou aktualizací aplikace po vyhlášení ve Sbírce zákonů;'
                . ' stav najdete v Mzdy → Legislativní pravidla mezd.',
                $year,
                implode(', ', $names),
            ),
            '/payroll/rulesets',
            1,
            [],
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
     * Validace snímku, které kontrola hlásí: bez trvale skrytých a bez těch,
     * které odstraní samo zamknutí. Jediný výběr pro přehled nálezů i pro
     * zúžení seznamu zaměstnanců na osoby nálezu.
     *
     * @param list<PayrollRunValidation> $validations
     * @return list<PayrollRunValidation>
     */
    private function reportedValidations(int $supplierId, array $validations): array
    {
        return array_values(array_filter(
            $this->warningSuppressions
                ->activeSet($supplierId)
                ->filterValidations($validations),
            static fn (PayrollRunValidation $validation): bool =>
                $validation->severity !== 'info'
                && !in_array($validation->code, self::RESOLVED_BY_LOCKING, true),
        ));
    }

    /**
     * Osoby nálezu kontroly před během, všechny, ne jen prvních pár.
     *
     * ── Co bylo špatně ──────────────────────────────────────────────────────
     * Přehled vypíše u nálezu nejvýš {@see self::MAX_ENTITIES} záznamů. U firmy
     * se 150 lidmi tak 125 z nich nebylo kde najít: „dalších 125 se do přehledu
     * nevešlo" a nic dalšího. Seznam zaměstnanců se proto umí zúžit na osoby
     * nálezu (`?filter=run_finding`) a ptá se TÉTO metody, tedy téhož snímku
     * a téhož výběru validací jako přehled. Dvě pravidla by se rozešla v počtu.
     *
     * Jen pro nálezy z validací snímku (docházka, mzdové složky, podmínky
     * vztahu …); ostatní nálezy mají vlastní hromadnou akci. Neznámý kód
     * i chyba snímku vrací prázdný výčet.
     *
     * @return list<int> id osob (`payroll_employees.id`)
     */
    public function findingEmployeeIds(
        int $supplierId,
        string $periodStart,
        string $paymentDate,
        string $code,
    ): array {
        try {
            $this->snapshotBuilder->assertRulesetCoverage($periodStart);
            $snapshot = $this->snapshotBuilder->build($supplierId, $periodStart, $paymentDate, null);
        } catch (\Throwable) {
            return [];
        }

        return $this->validationEmployeeIds(
            $supplierId,
            $this->reportedValidations($supplierId, $snapshot->validations),
            $code,
        );
    }

    /**
     * @param list<PayrollRunValidation> $validations
     * @return list<int>
     */
    private function validationEmployeeIds(int $supplierId, array $validations, string $code): array
    {
        $employeeIds = [];
        $employmentIds = [];
        foreach ($validations as $validation) {
            if ($validation->code !== $code || $validation->entityId === null) {
                continue;
            }
            if ($validation->entityType === 'employee') {
                $employeeIds[$validation->entityId] = true;
            } elseif ($validation->entityType === 'employment') {
                $employmentIds[$validation->entityId] = true;
            }
        }
        if ($employmentIds !== []) {
            $ids = array_keys($employmentIds);
            $statement = $this->db->pdo()->prepare(
                'SELECT DISTINCT employee_id FROM payroll_employments
                  WHERE supplier_id = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
            );
            $statement->execute([$supplierId, ...$ids]);
            foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $employeeId) {
                $employeeIds[(int) $employeeId] = true;
            }
        }
        $result = array_keys($employeeIds);
        sort($result);

        return $result;
    }

    /**
     * Nález s oříznutým výčtem dostane odkaz na seznam zaměstnanců zúžený na
     * VŠECHNY své osoby a jejich počet (osoba se dvěma vztahy je v seznamu
     * jednou, proto se počet osob posílá zvlášť).
     *
     * @param array<string,mixed> $finding
     * @param list<int> $employeeIds
     * @return array<string,mixed>
     */
    private static function withPeopleList(
        array $finding,
        array $employeeIds,
        string $periodStart,
        string $paymentDate,
    ): array {
        if ($employeeIds === [] || (int) $finding['entity_total'] <= count($finding['entities'])) {
            return $finding;
        }
        $finding['people_total'] = count($employeeIds);
        $finding['people_list_path'] = '/payroll/people?' . http_build_query([
            'filter' => PayrollPeopleRepository::FILTER_RUN_FINDING,
            'finding' => $finding['code'],
            'period' => substr($periodStart, 0, 7),
            'payment_date' => $paymentDate,
        ]);

        return $finding;
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
        /** @var array<string,?string> $tails společná věta skupiny bez osoby, null = věty se liší */
        $tails = [];
        /** @var array<string,true> $differs skupiny, jejichž záznamy nemají stejnou větu */
        $differs = [];
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
                    $validation->requiresOverride,
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
                    'requires_override' => $validation->requiresOverride,
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
            } elseif ($validation->requiresOverride && !$groups[$code]['requires_override']) {
                $groups[$code]['requires_override'] = true;
                if ($groups[$code]['severity'] !== 'blocker') {
                    $upgraded = PayrollRunReadinessImpact::describeValidation($code, 'warning', true);
                    $groups[$code]['impact'] = $upgraded['impact'];
                    $groups[$code]['severity'] = $upgraded['severity'];
                }
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
                $groups[$code]['remediation_path'] = self::commonRemediationPath(
                    $groups[$code]['remediation_path'],
                    $validation->remediationPath,
                );
            }
            $tail = self::messageWithoutSubject($validation->message);
            if (!array_key_exists($code, $tails)) {
                $tails[$code] = $tail;
            } elseif ($tails[$code] !== $tail) {
                $tails[$code] = null;
            }
            if ($groups[$code]['message'] !== $validation->message) {
                $differs[$code] = true;
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
        /*
         * Nadpis skupiny, jejíž záznamy se liší jen jménem na začátku věty.
         * Dřív dostala každá taková skupina TUTÉŽ obecnou větu „je třeba doplnit
         * podklady", takže chybějící docházka a chybějící mzdová složka vypadaly
         * jako dva stejné nálezy. Shodují-li se věty až na osobu, je nadpisem
         * společná věta; obecný text zůstává jen pro opravdu různé důvody.
         */
        foreach (array_keys($differs) as $code) {
            $groups[$code]['message'] = ($tails[$code] ?? null) !== null
                ? self::sentenceCase((string) $tails[$code])
                : 'U uvedených osob nebo pracovních vztahů je třeba doplnit podklady. Konkrétní důvod a odkaz k opravě najdete u každého záznamu.';
        }

        return array_values($groups);
    }

    /** Věta validace bez úvodního „Jméno Příjmení: "; bez dvojtečky celá. */
    private static function messageWithoutSubject(string $message): string
    {
        $position = mb_strpos($message, ': ');

        return $position === false ? $message : mb_substr($message, $position + 2);
    }

    private static function sentenceCase(string $text): string
    {
        return mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }

    /**
     * Společný odkaz skupiny nálezů, které se liší jen zúžením na vztah.
     *
     * Pět vstupů s haléři vedlo každý na Mzdové vstupy zúžené na jeden vztah,
     * skupina pak neměla odkaz žádný a hromadné zaokrouhlení celého období
     * (táž stránka bez zúžení) nebylo odkud otevřít. Odkazy na různá místa
     * zůstávají bez společného odkazu.
     */
    private static function commonRemediationPath(?string $left, ?string $right): ?string
    {
        if ($left === null || $right === null) {
            return null;
        }
        $strip = static function (string $path): string {
            $parts = explode('?', $path, 2);
            if (!isset($parts[1])) {
                return $path;
            }
            parse_str($parts[1], $query);
            if (isset($query['panel'])) {
                // Panel karty bez osoby nic neotevře.
                return $path;
            }
            unset($query['employment']);
            // Bez období by společný odkaz otevřel zpracovávaný měsíc, ne měsíc běhu.
            if (!isset($query['period'])) {
                return $path;
            }

            return $parts[0] . '?' . http_build_query($query);
        };
        $common = $strip($left);

        return $common === $strip($right) ? $common : null;
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
