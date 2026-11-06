<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use DateTimeImmutable;
use InvalidArgumentException;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\Payroll\PayrollEmploymentLifecycleSql;
use MyInvoice\Repository\Payroll\PayrollPersonNotFoundException;
use MyInvoice\Repository\Payroll\PayrollPersonStatutoryEvidenceConflictException;
use MyInvoice\Repository\Payroll\PayrollPersonStatutoryEvidenceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Payroll\IncomeTax\EmploymentRelationshipKindMapper;
use MyInvoice\Service\Payroll\IncomeTax\EmploymentRelationshipTaxInput;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxCalculator;
use PDO;
use UnexpectedValueException;

/**
 * Hromadné doplnění výchozí zákonné evidence osob bez cizího prvku.
 *
 * Běh z importu nemá u nikoho prohlášení, rezidenci, příslušnost ani slevu
 * důchodce a jediná zapisovací cesta byla karta osoby — 225 × totéž. Tahle
 * služba doplní jen to, co jde odvodit z toho, že osoba s cizinou nemá nic
 * společného, a nic víc:
 *
 *  - daňová rezidence → česká, příslušnost k pojištění → česká bez A1,
 *    sleva pracujícího důchodce → neuplatňuje se (jen pod 60 let věku);
 *  - zdravotní pojišťovna se NEDOPLŇUJE nikdy — je to skutečnost, kterou
 *    zná jen registrace (REGZEC/JMHZ) nebo zaměstnanec;
 *  - prohlášení poplatníka jen jako „nepodepsal" a jen na výslovné
 *    potvrzení účetní — bez něj nejde sleva, ale vymyšlený podpis by byl
 *    horší než chybějící údaj.
 *
 * Zápis jde VŽDY přes PayrollPersonStatutoryEvidenceRepository::save(), tedy
 * přes tentýž validátor, zmrazení období, souvislost řady i activity log jako
 * karta osoby. Sekce, která u osoby má jakýkoli řádek, se nechává být —
 * existující věta se nikdy nepřepisuje ani neukončuje.
 */
final class PayrollStatutoryEvidenceBulkDefaults
{
    /** Sekce, u kterých existuje výchozí stav bez cizího prvku. */
    public const DEFAULT_SECTIONS = [
        'tax_residences',
        'social_jurisdictions',
        'social_discount_claims',
    ];

    /** Prohlášení poplatníka — jen „nepodepsal" a jen na výslovné potvrzení. */
    public const DECLARATION_SECTION = 'tax_declarations';

    /**
     * Pod touhle hranicí se sleva pracujícího důchodce (§ 7a z. č. 589/1992
     * Sb.) za „neuplatňuje se" považuje bez ptaní. Není to zákonný věk
     * odchodu do důchodu, ale konzervativní mez: starobní důchod před
     * šedesátkou je výjimka, kterou hromadná akce nemá odhadovat.
     */
    private const PENSIONER_DISCOUNT_AGE_LIMIT = 60;

    private const MAX_EMPLOYEES = 2000;

    private const CHUNK_SIZE = 500;

    private const SAVEPOINT = 'payroll_statutory_bulk_defaults_person';

    private const NOTE_DEFAULTS = 'Doplněno hromadně účetní %s: výchozí stav bez cizího prvku';

    private const NOTE_DECLARATION = 'Doplněno hromadně účetní %s: prohlášení poplatníka nepodepsáno'
        . ' (potvrzeno při hromadném doplnění)';

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollPersonStatutoryEvidenceRepository $evidence,
        private readonly PayrollApprovedPeriodFreeze $freeze,
        private readonly ActivityLogger $activityLogger,
        private readonly MonthlyEmploymentIncomeTaxCalculator $taxCalculator,
        private readonly EmploymentRelationshipKindMapper $taxKinds = new EmploymentRelationshipKindMapper(),
    ) {}

    /**
     * Co by se u koho doplnilo a proč ne u ostatních. Nic nezapisuje.
     *
     * @param list<int|string>|null $employeeIds null = všechny osoby
     *     s pracovním vztahem v měsíci běhu (stejný výběr jako mzdový běh)
     * @return array<string,mixed>
     */
    public function preview(int $supplierId, string $effectiveOn, ?array $employeeIds): array
    {
        $monthStart = $this->monthStart($effectiveOn);
        $frozenThrough = $this->freeze->frozenThrough($supplierId);
        $requested = $employeeIds === null
            ? $this->runEmployeeIds($supplierId, $monthStart)
            : $this->normalizeIds($employeeIds);
        $assessments = $this->assess($supplierId, $monthStart, $frozenThrough, $requested);

        $people = [];
        $summary = [
            'people' => 0,
            'ready' => 0,
            'nothing_to_add' => 0,
            'excluded' => 0,
            'sections' => array_fill_keys(self::DEFAULT_SECTIONS, 0),
            'declaration_missing' => 0,
            'unsigned_declaration_withholding_risk' => 0,
            'health_insurer_missing' => 0,
        ];
        $ready = [];
        $declarationMissing = [];
        $excluded = [];
        $healthMissing = [];
        $withholdingRisk = [];
        foreach ($requested as $employeeId) {
            $row = $this->describe(
                $employeeId,
                $assessments[$employeeId] ?? null,
            );
            $people[] = $row;
            $summary['people']++;
            $summary[$row['status']]++;
            $person = ['employee_id' => $employeeId, 'full_name' => $row['full_name']];
            if ($row['status'] === 'excluded') {
                $excluded[] = $person + [
                    'reasons' => $row['reasons'],
                    'foreign_elements' => $row['foreign_elements'],
                ];
                continue;
            }
            if ($row['status'] === 'ready') {
                $ready[] = $employeeId;
            }
            foreach (self::DEFAULT_SECTIONS as $section) {
                if ($row['sections'][$section]['state'] === 'add') {
                    $summary['sections'][$section]++;
                }
            }
            if ($row['sections'][self::DECLARATION_SECTION]['state'] === 'missing') {
                $summary['declaration_missing']++;
                $declarationMissing[] = $employeeId;
            }
            if ($row['unsigned_declaration_withholding_risk']) {
                $summary['unsigned_declaration_withholding_risk']++;
                $withholdingRisk[] = $person + [
                    'employment_ids' => $row['withholding_employment_ids'],
                ];
            }
            if ($row['health_insurer_missing']) {
                $summary['health_insurer_missing']++;
                $healthMissing[] = $person;
            }
        }

        return [
            'effective_on' => $effectiveOn,
            'month_start' => $monthStart,
            'frozen_through' => $frozenThrough,
            'summary' => $summary,
            'ready_employee_ids' => $ready,
            'declaration_missing_employee_ids' => $declarationMissing,
            'excluded' => $excluded,
            'health_insurer_missing' => $healthMissing,
            'unsigned_declaration_withholding_risk' => $withholdingRisk,
            'people' => $people,
        ];
    }

    /**
     * Zapíše výchozí evidenci osobu po osobě.
     *
     * Každá osoba má vlastní transakci (uvnitř cizí transakce savepoint)
     * a zámek na řádku osoby — stav se před zápisem posuzuje znovu, pod
     * zámkem, takže souběžná úprava karty se nepřepíše ani neztratí.
     * Chyba jedné osoby ostatní nezastaví; vrací se applied/skipped/failed.
     *
     * @param list<int|string> $employeeIds
     * @param list<mixed> $sections podmnožina DEFAULT_SECTIONS
     * @return array<string,mixed>
     */
    public function apply(
        int $supplierId,
        string $effectiveOn,
        array $employeeIds,
        array $sections,
        bool $recordUnsignedDeclaration,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        $monthStart = $this->monthStart($effectiveOn);
        $ids = $this->normalizeIds($employeeIds);
        if ($ids === []) {
            throw new InvalidArgumentException('Vyberte aspoň jednu osobu.');
        }
        $targets = $this->normalizeSections($sections, $recordUnsignedDeclaration);
        $today = (new DateTimeImmutable('today'))->format('j. n. Y');

        $applied = [];
        $skipped = [];
        $failed = [];
        foreach ($ids as $employeeId) {
            $outcome = $this->applyPerson(
                $supplierId,
                $employeeId,
                $monthStart,
                $targets,
                $today,
                $userId,
                $ip,
                $userAgent,
            );
            if ($outcome['status'] === 'applied') {
                $applied[] = ['employee_id' => $employeeId] + $outcome['detail'];
            } elseif ($outcome['status'] === 'skipped') {
                $skipped[] = ['employee_id' => $employeeId] + $outcome['detail'];
            } else {
                $failed[] = ['employee_id' => $employeeId] + $outcome['detail'];
            }
        }

        $this->activityLogger->log(
            'payroll.person_statutory_evidence.bulk_defaults',
            $userId,
            null,
            null,
            [
                'effective_on' => $effectiveOn,
                'sections' => $targets,
                'record_unsigned_declaration' => $recordUnsignedDeclaration,
                'applied_employee_ids' => array_column($applied, 'employee_id'),
                'skipped_employee_ids' => array_column($skipped, 'employee_id'),
                'failed_employee_ids' => array_column($failed, 'employee_id'),
            ],
            $ip,
            $userAgent,
            $supplierId,
        );

        return [
            'effective_on' => $effectiveOn,
            'sections' => $targets,
            'counts' => [
                'applied' => count($applied),
                'skipped' => count($skipped),
                'failed' => count($failed),
            ],
            'applied' => $applied,
            'skipped' => $skipped,
            'failed' => $failed,
        ];
    }

    /**
     * @param list<string> $targets
     * @return array{status:'applied'|'skipped'|'failed',detail:array<string,mixed>}
     */
    private function applyPerson(
        int $supplierId,
        int $employeeId,
        string $monthStart,
        array $targets,
        string $today,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
        }

        try {
            if (!$this->lockEmployee($supplierId, $employeeId)) {
                $this->rollback($owns);
                return ['status' => 'skipped', 'detail' => ['reasons' => ['employee_not_found']]];
            }
            $assessment = $this->assess(
                $supplierId,
                $monthStart,
                $this->freeze->frozenThrough($supplierId),
                [$employeeId],
            )[$employeeId] ?? null;
            if ($assessment === null) {
                $this->rollback($owns);
                return ['status' => 'skipped', 'detail' => ['reasons' => ['employee_not_found']]];
            }
            $additions = $this->additions($assessment, $targets);
            if ($additions === []) {
                $this->rollback($owns);
                return ['status' => 'skipped', 'detail' => [
                    'reasons' => $assessment['excluded'] !== []
                        ? $assessment['excluded']
                        : ['nothing_to_add'],
                    'foreign_elements' => $assessment['foreign_elements'],
                ]];
            }

            $effectiveFrom = (string) $assessment['effective_from'];
            $view = $this->evidence->editorView($supplierId, $employeeId, $effectiveFrom)
                ?? throw new PayrollPersonNotFoundException();
            $this->evidence->save(
                $supplierId,
                $employeeId,
                $this->payload($view, $additions, $effectiveFrom, $today),
                $effectiveFrom,
                $userId,
                $ip,
                $userAgent,
            );
            $this->commit($owns);

            return ['status' => 'applied', 'detail' => [
                'sections' => $additions,
                'effective_from' => $effectiveFrom,
            ]];
        } catch (\Exception $exception) {
            // Odvolaná transakce (1020/1213) savepoint nemá. Vlastní transakce osoby je tím
            // vrácená, cizí musí dostat její vlastník.
            if (!TransactionFailure::rollbackIfAborted($this->db->pdo(), $exception)) {
                $this->rollback($owns);
            } elseif (!$owns) {
                throw $exception;
            }

            return ['status' => 'failed', 'detail' => [
                'message' => $this->failureMessage($exception),
            ]];
        }
    }

    /**
     * @param array<string,mixed> $assessment
     * @param list<string> $targets
     * @return list<string>
     */
    private function additions(array $assessment, array $targets): array
    {
        if ($assessment['excluded'] !== []) {
            return [];
        }
        $additions = [];
        foreach ($targets as $section) {
            $state = $assessment['sections'][$section]['state'] ?? null;
            if ($state === 'add' || ($section === self::DECLARATION_SECTION && $state === 'missing')) {
                $additions[] = $section;
            }
        }

        return $additions;
    }

    /**
     * Cílový stav pro save(): všechny stávající řádky beze změny + nová věta
     * v každé prázdné sekci.
     *
     * save() bere tělo jako CÍLOVÝ stav — vynechaný řádek by smazal. Proto se
     * posílá celý obsah editoru, a to pod zámkem osoby (viz applyPerson()).
     *
     * @param array<string,mixed> $view
     * @param list<string> $additions
     * @return array<string,mixed>
     */
    private function payload(array $view, array $additions, string $effectiveFrom, string $today): array
    {
        $sections = [];
        foreach (PayrollPersonStatutoryEvidenceRepository::EDITABLE_SECTIONS as $key) {
            $rows = [];
            foreach ($view['sections'][$key] ?? [] as $row) {
                $rows[] = $this->unchangedRow($row);
            }
            $sections[$key] = $rows;
        }
        foreach ($additions as $key) {
            if ($sections[$key] !== []) {
                throw new \LogicException(
                    "Sekce {$key} už řádek má — hromadné doplnění do ní psát nesmí.",
                );
            }
            $sections[$key] = [$this->defaultRow($key, $effectiveFrom, $today)];
        }

        return ['sections' => $sections];
    }

    /**
     * Řádek editoru zpět do těla požadavku. Věcná pole jdou jako text, protože
     * zapisovací cesta přijímá jen text nebo null; `id` a `row_version` zůstávají
     * čísla, aby save() poznal řádek i jeho verzi.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function unchangedRow(array $row): array
    {
        $result = [];
        foreach ($row as $field => $value) {
            $result[$field] = in_array($field, ['id', 'row_version'], true)
                || $value === null
                || is_string($value)
                ? $value
                : (string) $value;
        }

        return $result;
    }

    /** @return array<string,?string> */
    private function defaultRow(string $key, string $effectiveFrom, string $today): array
    {
        $values = match ($key) {
            'tax_residences' => [
                'residence' => 'czech-resident',
                'country_code' => 'CZ',
                'evidence_reference' => null,
            ],
            'social_jurisdictions' => [
                'jurisdiction' => 'czech_regime_verified',
                'foreign_country_code' => null,
                'jurisdiction_evidence_reference' => null,
                'a1_status' => 'not_applicable',
                'a1_certificate_reference' => null,
                'a1_valid_until' => null,
            ],
            'social_discount_claims' => [
                'status' => 'not_claimed',
                'evidence_reference' => null,
            ],
            self::DECLARATION_SECTION => [
                'status' => 'not-signed',
                'evidence_reference' => null,
            ],
            default => throw new \LogicException("Sekce {$key} nemá výchozí stav."),
        };

        return $values + [
            'effective_from' => $effectiveFrom,
            'effective_to' => null,
            'evidence_note' => sprintf(
                $key === self::DECLARATION_SECTION ? self::NOTE_DECLARATION : self::NOTE_DEFAULTS,
                $today,
            ),
        ];
    }

    /**
     * @param array<string,mixed>|null $assessment
     * @return array<string,mixed>
     */
    private function describe(int $employeeId, ?array $assessment): array
    {
        if ($assessment === null) {
            $sections = array_fill_keys(
                self::DEFAULT_SECTIONS,
                ['state' => 'excluded', 'reason' => null],
            );
            $sections[self::DECLARATION_SECTION] = ['state' => 'excluded', 'reason' => null];

            return [
                'employee_id' => $employeeId,
                'full_name' => null,
                'status' => 'excluded',
                'reasons' => ['employee_not_found'],
                'foreign_elements' => [],
                'effective_from' => null,
                'effective_from_basis' => null,
                'sections' => $sections,
                'health_insurer_missing' => false,
                'unsigned_declaration_withholding_risk' => false,
                'withholding_employment_ids' => [],
            ];
        }

        $status = 'nothing_to_add';
        if ($assessment['excluded'] !== []) {
            $status = 'excluded';
        } else {
            foreach (self::DEFAULT_SECTIONS as $section) {
                if ($assessment['sections'][$section]['state'] === 'add') {
                    $status = 'ready';
                    break;
                }
            }
        }

        return [
            'employee_id' => $employeeId,
            'full_name' => $assessment['full_name'],
            'status' => $status,
            'reasons' => $assessment['excluded'],
            'foreign_elements' => $assessment['foreign_elements'],
            'effective_from' => $assessment['effective_from'],
            'effective_from_basis' => $assessment['effective_from_basis'],
            'sections' => $assessment['sections'],
            'health_insurer_missing' => $assessment['health_insurer_missing'],
            'unsigned_declaration_withholding_risk' =>
                $assessment['withholding_employment_ids'] !== [],
            'withholding_employment_ids' => $assessment['withholding_employment_ids'],
        ];
    }

    /**
     * Posouzení osob MNOŽINOVĚ — pár dotazů nad celou množinou, ne dotaz na
     * osobu. Stejná funkce slouží náhledu i zápisu (tam pro jednu osobu pod
     * zámkem), takže náhled nemůže slibovat něco jiného, než zápis udělá.
     *
     * @param list<int> $employeeIds
     * @return array<int,array<string,mixed>> klíčováno ID; cizí nebo neexistující
     *     osoby ve výsledku chybí
     */
    private function assess(
        int $supplierId,
        string $monthStart,
        ?string $frozenThrough,
        array $employeeIds,
    ): array {
        if ($employeeIds === []) {
            return [];
        }
        $employees = [];
        $birthDates = [];
        $foreign = [];
        $employments = [];
        $counts = [];
        foreach (array_chunk($employeeIds, self::CHUNK_SIZE) as $chunk) {
            $in = implode(', ', array_fill(0, count($chunk), '?'));
            foreach ($this->rows(
                "SELECT id, full_name, birth_date
                   FROM payroll_employees
                  WHERE supplier_id = ? AND id IN ({$in})",
                [$supplierId, ...$chunk],
            ) as $row) {
                $employees[(int) $row['id']] = $row;
            }
            // Datum narození z identity (poslední verze, která ho má); starý
            // sloupec osoby je jen záloha pro kartu bez identity.
            foreach ($this->rows(
                "SELECT employee_id, birth_date
                   FROM payroll_person_identity_history
                  WHERE supplier_id = ? AND employee_id IN ({$in})
                    AND birth_date IS NOT NULL
                  ORDER BY employee_id, effective_from, id",
                [$supplierId, ...$chunk],
            ) as $row) {
                $birthDates[(int) $row['employee_id']] = (string) $row['birth_date'];
            }
            foreach ($this->foreignSignals($supplierId, $chunk, $in, $monthStart) as $row) {
                $foreign[(int) $row['employee_id']][(string) $row['signal']] = true;
            }
            foreach ($this->rows(
                "SELECT employment.id, employment.employee_id, employment.relation_type,
                        COALESCE(employment.actual_start_date, employment.start_date) AS start_on,
                        employment.end_date
                   FROM payroll_employments employment
                  WHERE employment.supplier_id = ? AND employment.employee_id IN ({$in})
                    AND employment.status NOT IN ('archived', 'no_show')
                    AND (employment.end_date IS NULL OR employment.end_date >= ?)
                  ORDER BY employment.employee_id, employment.id",
                [$supplierId, ...$chunk, $monthStart],
            ) as $row) {
                $employments[(int) $row['employee_id']][] = $row;
            }
            foreach ([...self::DEFAULT_SECTIONS, self::DECLARATION_SECTION, 'health_coverages'] as $section) {
                foreach ($this->rows(
                    sprintf(
                        "SELECT employee_id, COUNT(*) AS row_count
                           FROM %s
                          WHERE supplier_id = ? AND employee_id IN ({$in})
                          GROUP BY employee_id",
                        PayrollPersonStatutoryEvidenceRepository::sectionTable($section),
                    ),
                    [$supplierId, ...$chunk],
                ) as $row) {
                    $counts[$section][(int) $row['employee_id']] = (int) $row['row_count'];
                }
            }
        }

        $result = [];
        foreach ($employees as $employeeId => $employee) {
            $result[$employeeId] = $this->assessPerson(
                $supplierId,
                $employeeId,
                $employee,
                $birthDates[$employeeId]
                    ?? ($employee['birth_date'] === null ? null : (string) $employee['birth_date']),
                array_keys($foreign[$employeeId] ?? []),
                $employments[$employeeId] ?? [],
                $counts,
                $monthStart,
                $frozenThrough,
            );
        }

        return $result;
    }

    /**
     * Cizí prvek = cokoli, co naznačuje, že česká rezidence ani česká
     * příslušnost nemusí platit. Hledá se od začátku měsíce běhu dál (i
     * budoucí verze), povolení k pobytu a práci kdykoli — osoba, která ho
     * kdy potřebovala, se výchozím stavem posuzovat nemá.
     *
     * Kromě A1 a cizí legislativy ve smluvních podmínkách se bere i účast na
     * pojištění nebo daňový režim označený jako zahraniční a zahraniční věta
     * v už vedené zákonné evidenci: výchozí česká rezidence vedle zahraniční
     * zdravotní příslušnosti by si odporovala.
     *
     * @param list<int> $chunk
     * @return list<array<string,mixed>>
     */
    private function foreignSignals(int $supplierId, array $chunk, string $in, string $monthStart): array
    {
        $current = '(effective_to IS NULL OR effective_to >= ?)';
        $terms = "FROM payroll_employment_terms term
                  JOIN payroll_employments employment
                    ON employment.supplier_id = term.supplier_id
                   AND employment.id = term.employment_id
                 WHERE term.supplier_id = ? AND employment.employee_id IN ({$in})
                   AND (term.effective_to IS NULL OR term.effective_to >= ?)";

        return $this->rows(
            "SELECT employee_id, 'address_abroad' AS `signal`
               FROM payroll_person_addresses
              WHERE supplier_id = ? AND employee_id IN ({$in})
                AND country_code <> 'CZ' AND {$current}
             UNION ALL
             SELECT employee_id, 'foreign_citizenship'
               FROM payroll_person_identity_history
              WHERE supplier_id = ? AND employee_id IN ({$in})
                AND citizenship_country_code IS NOT NULL
                AND citizenship_country_code <> 'CZ' AND {$current}
             UNION ALL
             SELECT employee_id, 'foreign_permit'
               FROM payroll_person_foreign_permits
              WHERE supplier_id = ? AND employee_id IN ({$in})
             UNION ALL
             SELECT employment.employee_id, 'foreign_legislation' {$terms}
                AND term.foreign_legislation_country_code IS NOT NULL
             UNION ALL
             SELECT employment.employee_id, 'a1_certificate' {$terms}
                AND term.a1_certificate_until IS NOT NULL
             UNION ALL
             SELECT employment.employee_id, 'foreign_insurance_or_tax_regime' {$terms}
                AND (term.social_insurance_participation = 'foreign'
                     OR term.health_insurance_participation = 'foreign'
                     OR term.tax_regime = 'foreign')
             UNION ALL
             SELECT employee_id, 'foreign_statutory_evidence'
               FROM payroll_person_health_coverage_history
              WHERE supplier_id = ? AND employee_id IN ({$in}) AND {$current}
                AND (jurisdiction = 'foreign_regime_verified' OR foreign_country_code IS NOT NULL)
             UNION ALL
             SELECT employee_id, 'foreign_statutory_evidence'
               FROM payroll_person_social_jurisdictions
              WHERE supplier_id = ? AND employee_id IN ({$in}) AND {$current}
                AND (jurisdiction = 'foreign_regime_verified'
                     OR foreign_country_code IS NOT NULL
                     OR a1_status = 'verified')
             UNION ALL
             SELECT employee_id, 'foreign_statutory_evidence'
               FROM payroll_person_tax_residences
              WHERE supplier_id = ? AND employee_id IN ({$in}) AND {$current}
                AND (residence = 'non-resident'
                     OR (country_code IS NOT NULL AND country_code <> 'CZ'))",
            [
                $supplierId, ...$chunk, $monthStart,
                $supplierId, ...$chunk, $monthStart,
                $supplierId, ...$chunk,
                $supplierId, ...$chunk, $monthStart,
                $supplierId, ...$chunk, $monthStart,
                $supplierId, ...$chunk, $monthStart,
                $supplierId, ...$chunk, $monthStart,
                $supplierId, ...$chunk, $monthStart,
                $supplierId, ...$chunk, $monthStart,
            ],
        );
    }

    /**
     * @param array<string,mixed> $employee
     * @param list<string> $foreignElements
     * @param list<array<string,mixed>> $employments
     * @param array<string,array<int,int>> $counts
     * @return array<string,mixed>
     */
    private function assessPerson(
        int $supplierId,
        int $employeeId,
        array $employee,
        ?string $birthDate,
        array $foreignElements,
        array $employments,
        array $counts,
        string $monthStart,
        ?string $frozenThrough,
    ): array {
        sort($foreignElements);
        $excluded = [];
        $effectiveFrom = null;
        $basis = null;
        if ($employments === []) {
            $excluded[] = 'no_employment_in_period';
        } else {
            [$effectiveFrom, $basis] = $this->effectiveFrom($employments, $monthStart, $frozenThrough);
            $latestEnd = null;
            foreach ($employments as $employment) {
                if ($employment['end_date'] === null) {
                    $latestEnd = null;
                    break;
                }
                $latestEnd = max((string) $latestEnd, (string) $employment['end_date']);
            }
            if ($latestEnd !== null && $latestEnd < $effectiveFrom) {
                $excluded[] = 'period_frozen';
            }
        }
        if ($foreignElements !== []) {
            $excluded[] = 'foreign_element';
        }

        $sections = [];
        foreach (self::DEFAULT_SECTIONS as $section) {
            if (($counts[$section][$employeeId] ?? 0) > 0) {
                $sections[$section] = ['state' => 'exists', 'reason' => null];
                continue;
            }
            if ($excluded !== []) {
                $sections[$section] = ['state' => 'excluded', 'reason' => null];
                continue;
            }
            $reason = $section === 'social_discount_claims'
                ? $this->pensionerDiscountObstacle($birthDate, (string) $effectiveFrom)
                : null;
            $sections[$section] = $reason === null
                ? ['state' => 'add', 'reason' => null]
                : ['state' => 'excluded', 'reason' => $reason];
        }
        $declarationMissing = ($counts[self::DECLARATION_SECTION][$employeeId] ?? 0) === 0;
        $sections[self::DECLARATION_SECTION] = [
            'state' => $declarationMissing
                ? ($excluded === [] ? 'missing' : 'excluded')
                : 'exists',
            'reason' => null,
        ];

        return [
            'employee_id' => $employeeId,
            'full_name' => (string) $employee['full_name'],
            'effective_from' => $effectiveFrom,
            'effective_from_basis' => $basis,
            'excluded' => $excluded,
            'foreign_elements' => $foreignElements,
            'sections' => $sections,
            'health_insurer_missing' => ($counts['health_coverages'][$employeeId] ?? 0) === 0,
            'withholding_employment_ids' => $declarationMissing && $excluded === []
                ? $this->withholdingEmploymentIds($supplierId, $employments)
                : [],
        ];
    }

    /**
     * Účinnost od 1. dne měsíce běhu; u pozdějšího nástupu od měsíce nástupu;
     * do období uzavřeného schválenou mzdou se nepíše vůbec — schválený měsíc
     * si drží vlastní snímek a hromadná akce do jeho historie nesahá.
     *
     * @param non-empty-list<array<string,mixed>> $employments
     * @return array{0:string,1:string}
     */
    private function effectiveFrom(array $employments, string $monthStart, ?string $frozenThrough): array
    {
        $earliest = null;
        foreach ($employments as $employment) {
            $start = $employment['start_on'] === null ? $monthStart : (string) $employment['start_on'];
            $earliest = $earliest === null ? $start : min($earliest, $start);
        }
        $from = $monthStart;
        $basis = 'run_month';
        if ($earliest !== null && $earliest > $monthStart) {
            $from = substr($earliest, 0, 8) . '01';
            $basis = 'employment_start';
        }
        $open = PayrollApprovedPeriodFreeze::firstOpenMonth($from, $frozenThrough);
        if ($open !== $from) {
            $from = $open;
            $basis = 'after_frozen_period';
        }

        return [$from, $basis];
    }

    private function pensionerDiscountObstacle(?string $birthDate, string $effectiveFrom): ?string
    {
        if ($birthDate === null) {
            return 'birth_date_missing';
        }
        $birth = DateTimeImmutable::createFromFormat('!Y-m-d', $birthDate);
        $at = new DateTimeImmutable($effectiveFrom);
        if ($birth === false || $birth > $at) {
            return 'birth_date_invalid';
        }

        return $birth->diff($at)->y >= self::PENSIONER_DISCOUNT_AGE_LIMIT
            ? 'age_60_or_more'
            : null;
    }

    /**
     * Vztahy, u kterých by nepodepsané prohlášení mohlo přepnout zdanění na
     * srážku (§ 6 odst. 4 ZDP). Zařazení do skupiny rozhoduje výpočet daně
     * ({@see MonthlyEmploymentIncomeTaxCalculator::withholdingGroupWithoutSignedDeclaration()}),
     * ne kopie jeho pravidel; srážka pak nastane jen při úhrnu pod rozhodnou
     * částkou, což se z evidence předem poznat nedá — proto „riziko". Podle
     * § 6 odst. 4 písm. b) ho nese každý druh vztahu, pracovní poměr
     * s měsícem pod rozhodnou částkou stejně jako dohoda.
     *
     * @param list<array<string,mixed>> $employments
     * @return list<int>
     */
    private function withholdingEmploymentIds(int $supplierId, array $employments): array
    {
        $ids = [];
        foreach ($employments as $employment) {
            try {
                $kind = $this->taxKinds->fromDatabaseRelationType((string) $employment['relation_type']);
            } catch (UnexpectedValueException) {
                continue;
            }
            $group = $this->taxCalculator->withholdingGroupWithoutSignedDeclaration(
                new EmploymentRelationshipTaxInput(
                    'employment:' . (int) $employment['id'],
                    "supplier:{$supplierId}",
                    $kind,
                    [],
                ),
            );
            if ($group !== null) {
                $ids[] = (int) $employment['id'];
            }
        }

        return $ids;
    }

    /**
     * Osoby, které by vzal mzdový běh za měsíc — stejné podmínky jako
     * PayrollRunSnapshotBuilder::employmentRows() (bez zúžení na účtárnu).
     * Tamní dotaz je součástí snímku a nese stovky sloupců; sdílí se proto
     * stav vztahu k datu ({@see PayrollEmploymentLifecycleSql}), ne celý SQL.
     *
     * @return list<int>
     */
    private function runEmployeeIds(int $supplierId, string $monthStart): array
    {
        $monthEnd = (new DateTimeImmutable($monthStart))->modify('last day of this month')->format('Y-m-d');
        $ids = [];
        foreach ($this->rows(
            'WITH effective_employment AS (
                    SELECT employment.*,
                           ' . PayrollEmploymentLifecycleSql::effectiveStatusAtPlaceholder() . '
                               AS effective_status
                      FROM payroll_employments employment
                     WHERE employment.supplier_id = ?
                 )
             SELECT DISTINCT employment.employee_id
               FROM effective_employment employment
              WHERE employment.supplier_id = ?
                AND employment.effective_status IS NOT NULL
                AND employment.effective_status NOT IN ("archived", "no_show")
                AND COALESCE(employment.actual_start_date, employment.start_date, "1900-01-01") <= ?
                AND (
                    employment.end_date IS NULL
                    OR employment.end_date >= ?
                    OR EXISTS (
                        SELECT 1
                          FROM payroll_inputs post_termination_input
                         WHERE post_termination_input.supplier_id = employment.supplier_id
                           AND post_termination_input.employment_id = employment.id
                           AND post_termination_input.period_start = ?
                           AND post_termination_input.status <> "cancelled"
                    )
                )
              ORDER BY employment.employee_id',
            [$monthEnd, $supplierId, $supplierId, $monthEnd, $monthStart, $monthStart],
        ) as $row) {
            $ids[] = (int) $row['employee_id'];
        }

        return $ids;
    }

    /**
     * @param list<mixed> $sections
     * @return list<string>
     */
    private function normalizeSections(array $sections, bool $recordUnsignedDeclaration): array
    {
        $targets = [];
        foreach ($sections as $section) {
            if (!is_string($section)) {
                throw new InvalidArgumentException('Sekce musí být seznam názvů.');
            }
            if ($section === self::DECLARATION_SECTION) {
                if (!$recordUnsignedDeclaration) {
                    throw new InvalidArgumentException(
                        'Prohlášení poplatníka se hromadně zapisuje jen jako nepodepsané'
                        . ' a jen s výslovným potvrzením (record_unsigned_declaration).',
                    );
                }
                continue;
            }
            if (!in_array($section, self::DEFAULT_SECTIONS, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Sekce „%s“ nemá výchozí stav. Přípustné: %s.',
                    $section,
                    implode(', ', self::DEFAULT_SECTIONS),
                ));
            }
            $targets[$section] = true;
        }
        $result = array_values(array_filter(
            self::DEFAULT_SECTIONS,
            static fn (string $section): bool => isset($targets[$section]),
        ));
        if ($recordUnsignedDeclaration) {
            $result[] = self::DECLARATION_SECTION;
        }
        if ($result === []) {
            throw new InvalidArgumentException('Vyberte aspoň jednu sekci k doplnění.');
        }

        return $result;
    }

    /**
     * @param list<mixed> $employeeIds
     * @return list<int>
     */
    private function normalizeIds(array $employeeIds): array
    {
        $ids = [];
        foreach ($employeeIds as $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!is_int($id)) {
                throw new InvalidArgumentException('ID osoby musí být kladné celé číslo.');
            }
            $ids[$id] = true;
        }
        if (count($ids) > self::MAX_EMPLOYEES) {
            throw new InvalidArgumentException(sprintf(
                'Najednou lze zpracovat nejvýše %d osob.',
                self::MAX_EMPLOYEES,
            ));
        }

        return array_keys($ids);
    }

    private function monthStart(string $effectiveOn): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $effectiveOn);
        if ($date === false || $date->format('Y-m-d') !== $effectiveOn) {
            throw new InvalidArgumentException('effective_on musí být datum YYYY-MM-DD.');
        }

        return $date->format('Y-m-01');
    }

    private function lockEmployee(int $supplierId, int $employeeId): bool
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_employees
              WHERE supplier_id = ? AND id = ?
              FOR UPDATE'
        );
        $statement->execute([$supplierId, $employeeId]);

        return $statement->fetchColumn() !== false;
    }

    private function commit(bool $owns): void
    {
        $pdo = $this->db->pdo();
        if ($owns) {
            $pdo->commit();
            return;
        }
        $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
    }

    private function rollback(bool $owns): void
    {
        $pdo = $this->db->pdo();
        if ($owns) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return;
        }
        $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
        $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
    }

    private function failureMessage(\Exception $exception): string
    {
        return match (true) {
            $exception instanceof PayrollPersonNotFoundException => 'Zaměstnanec nenalezen.',
            $exception instanceof PayrollPersonStatutoryEvidenceConflictException,
            $exception instanceof InvalidArgumentException => $exception->getMessage(),
            default => 'Zákonnou evidenci osoby se nepodařilo uložit.',
        };
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string,mixed>>
     */
    private function rows(string $sql, array $params): array
    {
        $statement = $this->db->pdo()->prepare($sql);
        $statement->execute($params);

        /** @var list<array<string,mixed>> */
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
