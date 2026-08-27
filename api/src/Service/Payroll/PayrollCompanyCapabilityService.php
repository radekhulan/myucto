<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenarioSelectorResolver;
use PDO;

final class PayrollCompanyCapabilityService
{
    /** @var array<string,string> */
    private const RELATION_CAPABILITIES = [
        'employment' => 'hpp',
        'small_scale_employment' => 'hpp',
        'dpp' => 'dpp',
        'dpc' => 'dpc',
        'partner_dependent' => 'statutory_body',
        'statutory_body' => 'statutory_body',
    ];

    private ?JmhzScenarioSelectorResolver $scenarioResolver = null;

    public function __construct(
        private readonly Connection $db,
        private readonly SupportMatrix $supportMatrix,
    ) {}

    /**
     * @return array{
     *   production_ready:bool,
     *   assessed_from:?string,
     *   blockers:list<array{
     *     code:string,
     *     capability_key:string,
     *     source_type:string,
     *     source_id:int,
     *     message:string,
     *     parameters:array<string,mixed>
     *   }>
     * }
     */
    public function assess(int $supplierId, ?string $startPeriod): array
    {
        return $this->assessment($supplierId, $startPeriod, false);
    }

    /**
     * Stejný preflight jako v GET, ale s řádkovými zámky. Volající tím drží
     * rozhodující tenantová fakta až do změny stavu modulu a uložení kvalifikace.
     *
     * @return array{
     *   production_ready:bool,
     *   assessed_from:?string,
     *   blockers:list<array{
     *     code:string,
     *     capability_key:string,
     *     source_type:string,
     *     source_id:int,
     *     message:string,
     *     parameters:array<string,mixed>
     *   }>
     * }
     */
    public function assessForUpdate(int $supplierId, ?string $startPeriod): array
    {
        if (!$this->db->pdo()->inTransaction()) {
            throw new \LogicException('Produkční capability assessment vyžaduje aktivní transakci.');
        }

        return $this->assessment($supplierId, $startPeriod, true);
    }

    /**
     * @return array{
     *   production_ready:bool,
     *   assessed_from:?string,
     *   blockers:list<array{
     *     code:string,
     *     capability_key:string,
     *     source_type:string,
     *     source_id:int,
     *     message:string,
     *     parameters:array<string,mixed>
     *   }>
     * }
     */
    private function assessment(int $supplierId, ?string $startPeriod, bool $forUpdate): array
    {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException('Firma capability assessmentu musí být kladné číslo.');
        }
        $assessedFrom = self::periodStart($startPeriod);
        if ($assessedFrom === null) {
            return [
                'production_ready' => true,
                'assessed_from' => null,
                'blockers' => [],
            ];
        }

        $matrix = $this->supportMatrix->all();
        $employmentCapabilities = self::availability($matrix['employment_types']);
        $featureCapabilities = self::availability($matrix['features']);
        $blockers = [];
        foreach ($this->employmentTerms($supplierId, $assessedFrom, $forUpdate) as $row) {
            $employmentId = self::int($row, 'employment_id');
            $termId = self::int($row, 'term_id');
            $employmentCode = self::string($row, 'employment_code');
            $relationType = self::string($row, 'relation_type');
            $relationCapability = self::RELATION_CAPABILITIES[$relationType] ?? null;
            if ($relationCapability === null
                || !($employmentCapabilities[$relationCapability] ?? false)
            ) {
                $blockers[] = $this->blocker(
                    'unsupported_relation_type',
                    $relationCapability ?? $relationType,
                    'employment',
                    $employmentId,
                    "Pracovní vztah {$employmentCode} má typ, který produkční výpočet nepodporuje.",
                    [
                        'employment_code' => $employmentCode,
                        'relation_type' => $relationType,
                    ],
                );
            }

            $foreignRegimes = [];
            foreach ([
                'social_insurance_participation' => 'social',
                'health_insurance_participation' => 'health',
                'tax_regime' => 'tax',
            ] as $field => $regime) {
                if (($row[$field] ?? null) === 'foreign') {
                    $foreignRegimes[] = $regime;
                }
            }
            if ($foreignRegimes !== []
                && !($employmentCapabilities['foreign_regime'] ?? false)
            ) {
                $blockers[] = $this->blocker(
                    'foreign_employment_regime',
                    'foreign_regime',
                    'employment_term',
                    $termId,
                    "Pracovní vztah {$employmentCode} používá zahraniční pojistný nebo daňový režim, který produkční matice nepodporuje.",
                    [
                        'employment_code' => $employmentCode,
                        'regimes' => $foreignRegimes,
                    ],
                );
            }

            $activityCode = self::nullableString($row, 'activity_code');
            if ($activityCode !== null) {
                $selection = $this->scenarioResolver()->resolve(
                    $activityCode,
                    self::nullableString($row, 'jmhz_relationship_detail_code'),
                );
                if (!$selection['preparation_supported']
                    && $selection['readiness_issue_code'] !== null
                    && !($featureCapabilities['jmhz_special_scenarios'] ?? false)
                ) {
                    $blockers[] = $this->blocker(
                        'unsupported_jmhz_scenario',
                        'jmhz_special_scenarios',
                        'employment_term',
                        $termId,
                        "Pracovní vztah {$employmentCode} vyžaduje zvláštní scénář JMHZ, který zatím nelze podat v ostrém provozu.",
                        [
                            'employment_code' => $employmentCode,
                            'activity_code' => $activityCode,
                            'relationship_detail_code' => self::nullableString(
                                $row,
                                'jmhz_relationship_detail_code',
                            ),
                            'scenario_key' => $selection['evidence']['scenario_key'] ?? null,
                            'readiness_issue_code' => $selection['readiness_issue_code'],
                        ],
                    );
                }
            }
        }

        if (!($employmentCapabilities['foreign_regime'] ?? false)) {
            foreach ([
                [
                    'table' => 'payroll_person_social_jurisdictions',
                    'code' => 'foreign_social_jurisdiction',
                    'label' => 'sociální zabezpečení',
                ],
                [
                    'table' => 'payroll_person_health_coverage_history',
                    'code' => 'foreign_health_jurisdiction',
                    'label' => 'zdravotní pojištění',
                ],
            ] as $jurisdiction) {
                foreach ($this->foreignJurisdictions(
                    $jurisdiction['table'],
                    $supplierId,
                    $assessedFrom,
                    $forUpdate,
                ) as $row) {
                    $name = self::string($row, 'full_name');
                    $blockers[] = $this->blocker(
                        $jurisdiction['code'],
                        'foreign_regime',
                        $jurisdiction['table'],
                        self::int($row, 'evidence_id'),
                        "Zaměstnanec {$name} má doložený zahraniční režim pro {$jurisdiction['label']}, který produkční matice nepodporuje.",
                        [
                            'employee_name' => $name,
                            'country_code' => self::nullableString($row, 'foreign_country_code'),
                        ],
                    );
                }
            }
        }

        $blockers = self::uniqueBlockers($blockers);

        return [
            'production_ready' => $blockers === [],
            'assessed_from' => $assessedFrom,
            'blockers' => $blockers,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function employmentTerms(int $supplierId, string $assessedFrom, bool $forUpdate): array
    {
        $sql =
            'SELECT employment.id AS employment_id,
                    employment.code AS employment_code,
                    employment.relation_type,
                    terms.id AS term_id,
                    terms.activity_code,
                    terms.jmhz_relationship_detail_code,
                    terms.social_insurance_participation,
                    terms.health_insurance_participation,
                    terms.tax_regime
               FROM payroll_employments employment
               JOIN payroll_employment_terms terms
                 ON terms.supplier_id = employment.supplier_id
                AND terms.employment_id = employment.id
              WHERE employment.supplier_id = ?
                AND (
                    employment.status IN ("planned", "preregistered", "active", "suspended")
                    OR (employment.status = "ended" AND employment.end_date >= ?)
                )
                AND (terms.effective_to IS NULL OR terms.effective_to >= ?)
              ORDER BY employment.id, terms.effective_from, terms.id';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, $assessedFrom, $assessedFrom]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    private function foreignJurisdictions(
        string $table,
        int $supplierId,
        string $assessedFrom,
        bool $forUpdate,
    ): array {
        if (!in_array($table, [
            'payroll_person_social_jurisdictions',
            'payroll_person_health_coverage_history',
        ], true)) {
            throw new \LogicException('Neznámý zdroj zahraniční jurisdikce.');
        }
        $sql =
            "SELECT evidence.id AS evidence_id,
                    evidence.employee_id,
                    evidence.foreign_country_code,
                    employee.full_name
               FROM {$table} evidence
               JOIN payroll_employees employee
                 ON employee.supplier_id = evidence.supplier_id
                AND employee.id = evidence.employee_id
              WHERE evidence.supplier_id = ?
                AND evidence.jurisdiction = \"foreign_regime_verified\"
                AND (evidence.effective_to IS NULL OR evidence.effective_to >= ?)
                AND EXISTS (
                    SELECT 1
                      FROM payroll_employments employment
                     WHERE employment.supplier_id = evidence.supplier_id
                       AND employment.employee_id = evidence.employee_id
                       AND (
                           employment.status IN (\"planned\", \"preregistered\", \"active\", \"suspended\")
                           OR (employment.status = \"ended\" AND employment.end_date >= ?)
                       )
                )
              ORDER BY evidence.employee_id, evidence.effective_from, evidence.id";
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, $assessedFrom, $assessedFrom]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string,mixed> $parameters
     * @return array{code:string,capability_key:string,source_type:string,source_id:int,message:string,parameters:array<string,mixed>}
     */
    private function blocker(
        string $code,
        string $capabilityKey,
        string $sourceType,
        int $sourceId,
        string $message,
        array $parameters,
    ): array {
        return [
            'code' => $code,
            'capability_key' => $capabilityKey,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'message' => $message,
            'parameters' => $parameters,
        ];
    }

    private function scenarioResolver(): JmhzScenarioSelectorResolver
    {
        return $this->scenarioResolver ??= JmhzScenarioSelectorResolver::load();
    }

    /**
     * @param list<array{key:string,available:bool}> $capabilities
     * @return array<string,bool>
     */
    private static function availability(array $capabilities): array
    {
        $availability = [];
        foreach ($capabilities as $capability) {
            $availability[$capability['key']] = $capability['available'];
        }

        return $availability;
    }

    private static function periodStart(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])(?:-01)?$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Počátek capability assessmentu musí být první den měsíce.');
        }

        return substr($value, 0, 7) . '-01';
    }

    /** @param array<string,mixed> $row */
    private static function int(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new \LogicException("Databázové pole {$key} není celé číslo.");
    }

    /** @param array<string,mixed> $row */
    private static function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \LogicException("Databázové pole {$key} není neprázdný řetězec.");
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function nullableString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \LogicException("Databázové pole {$key} není řetězec nebo null.");
        }

        return $value === '' ? null : $value;
    }

    /**
     * @param list<array{code:string,capability_key:string,source_type:string,source_id:int,message:string,parameters:array<string,mixed>}> $blockers
     * @return list<array{code:string,capability_key:string,source_type:string,source_id:int,message:string,parameters:array<string,mixed>}>
     */
    private static function uniqueBlockers(array $blockers): array
    {
        $unique = [];
        foreach ($blockers as $blocker) {
            $key = $blocker['code'] . ':' . $blocker['source_type'] . ':' . $blocker['source_id'];
            $unique[$key] = $blocker;
        }
        ksort($unique, SORT_STRING);

        return array_values($unique);
    }
}
