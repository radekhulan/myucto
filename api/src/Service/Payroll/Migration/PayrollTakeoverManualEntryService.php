<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Service\Payroll\Import\OpeningBalance\OpeningBalanceMonthValidator;
use MyInvoice\Service\Payroll\PayrollOpeningBalanceService;
use PDO;

/**
 * Ruční zadání převzatých mezd — jedno zadání pro OBĚ vrstvy převzatého měsíce.
 *
 * ── Proč to existuje ────────────────────────────────────────────────────────
 * Firma, jejíž předchozí program nevydá export ani hlášení JMHZ (nebo vedla
 * mzdy na papíře), musela převzatý měsíc zadat dvakrát a pokaždé jinde:
 * počáteční stavy v kartě zaměstnance (čte je roční zúčtování, vyúčtování daně
 * a potvrzení § 38j) a převzaté mzdy tabulkou v haléřích s interním `employee_id`
 * (čte je ELDP a převzatý běh). Dvě zadání téhož se rozejdou.
 *
 * Tady se měsíc zadá jednou, po pracovních vztazích, a zapíše se:
 *
 *  - (B) převzatá mzda za VZTAH × MĚSÍC přes {@see PayrollMigrationReferenceTotalsWriter}
 *    — stejnou cestou jako tabulkový import. Měsíc, který už převzatou mzdu má
 *    (třeba z převodu PAMICA), se přepíše pod svým zdrojem, aby nevznikl druhý
 *    řádek téhož měsíce.
 *  - (A) počáteční stav za OSOBU × MĚSÍC přes {@see PayrollOpeningBalanceService}
 *    jako součet vztahů — týmž pravidlem, jakým se sčítá převzatý měsíc na osobu
 *    ({@see PayrollTakeoverYear::personMonthTotals()}), s kontrolou úplnosti
 *    (prázdné není nula).
 *
 * Obojí v jedné transakci: buď vzniknou obě vrstvy, nebo žádná.
 */
final class PayrollTakeoverManualEntryService
{
    private const SAVEPOINT = 'payroll_takeover_manual';

    /** Zdroj ručně zadaného měsíce, který dosud převzatou mzdu neměl. */
    private const SOURCE = 'other';

    /** Peněžní pole řádku (haléře). */
    public const MONEY_FIELDS = [
        'gross_minor',
        'net_minor',
        'deductions_minor',
        'net_payable_minor',
        'social_base_minor',
        'health_base_minor',
        'employee_social_minor',
        'employee_health_minor',
        'employer_social_minor',
        'employer_health_minor',
        'health_minimum_top_up_minor',
        'advance_base_minor',
        'advance_tax_minor',
        'withholding_base_minor',
        'withholding_tax_minor',
        'applied_credits_minor',
        'applied_child_credit_minor',
        'tax_bonus_minor',
    ];

    /**
     * Čerpání ročních košů osvobozených benefitů (§ 6 odst. 9 písm. d) a m) ZDP)
     * v haléřích. Nejsou to příjmy měsíce (ty nese hrubá mzda), ale úhrn, proti
     * kterému se poměřuje zbytek roku; bez nich MyÚčto osvobodí celý limit znovu.
     * Pole, které klient nepošle, nechá dřív převzatou hodnotu beze změny.
     */
    public const BENEFIT_FIELDS = [
        'old_age_savings_minor',
        'non_cash_health_benefit_minor',
        'non_cash_leisure_benefit_minor',
    ];

    /** Doby (celá čísla: dny, setiny dne, minuty). */
    public const DURATION_FIELDS = [
        'insurance_days',
        'excluded_days',
        'worked_days_hundredths',
        'worked_minutes',
    ];

    /**
     * Pole počátečního stavu (A) => pole řádku, ze kterých se sčítá přes vztahy.
     * Příjem rozhodný pro bonus se plní základem zálohy — stejně jako při
     * schválení vlastního běhu i při importu hlášení.
     */
    private const OPENING_MAP = [
        'social_assessment_base_minor_units' => 'social_base_minor',
        'health_assessment_base_minor_units' => 'health_base_minor',
        'health_employee_contribution_minor_units' => 'employee_health_minor',
        'health_employer_contribution_minor_units' => 'employer_health_minor',
        'health_minimum_top_up_minor_units' => 'health_minimum_top_up_minor',
        'advance_base_minor_units' => 'advance_base_minor',
        'advance_tax_minor_units' => 'advance_tax_minor',
        'withholding_base_minor_units' => 'withholding_base_minor',
        'withholding_tax_minor_units' => 'withholding_tax_minor',
        'applied_non_refundable_credits_minor_units' => 'applied_credits_minor',
        'applied_child_credit_minor_units' => 'applied_child_credit_minor',
        'tax_bonus_minor_units' => 'tax_bonus_minor',
        'bonus_qualifying_income_minor_units' => 'advance_base_minor',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollTakeoverCoverage $coverage,
        private readonly PayrollTakeoverReader $reader,
        private readonly PayrollMigrationReferenceTotalsWriter $writer,
        private readonly PayrollOpeningBalanceService $openings,
    ) {}

    /**
     * Podklad formuláře: převzaté měsíce, vztahy osoby a co už je zadané.
     *
     * @return array<string,mixed>
     */
    public function form(int $supplierId, int $employeeId, int $year): array
    {
        $takeoverMonths = $this->coverage->takeoverMonths($supplierId, $year);
        $employments = $this->employments($supplierId, $employeeId, $year, $takeoverMonths);
        $existing = $this->existingWages($supplierId, $employeeId, $year);
        $opening = $this->openings->current($supplierId, $employeeId, $year);
        $openingRows = [];
        foreach ($opening['months'] as $row) {
            $openingRows[(int) $row['month']] = $row;
        }

        $rows = [];
        $firstByMonth = [];
        foreach ($employments as $employment) {
            foreach ($employment['months'] as $month) {
                $wage = $existing[$employment['id']][$month] ?? null;
                $row = ['employment_id' => $employment['id'], 'month' => $month];
                foreach (self::MONEY_FIELDS as $field) {
                    $row[$field] = 0;
                }
                foreach (self::DURATION_FIELDS as $field) {
                    $row[$field] = 0;
                }
                $row['old_age_savings_minor'] = (int) ($wage?->oldAgeSavingsContributionMinor ?? 0);
                $row['non_cash_health_benefit_minor'] = (int) ($wage?->nonCashHealthBenefitMinor ?? 0);
                $row['non_cash_leisure_benefit_minor'] = (int) ($wage?->nonCashLeisureBenefitMinor ?? 0);
                $row['pension_participation'] = true;
                $row['payout_date'] = null;
                if ($wage !== null) {
                    $values = $wage->toArray();
                    foreach ([
                        'gross_minor', 'net_minor', 'deductions_minor', 'net_payable_minor',
                        'social_base_minor', 'health_base_minor', 'employee_social_minor',
                        'employee_health_minor', 'employer_social_minor', 'employer_health_minor',
                        'advance_tax_minor', 'withholding_tax_minor', 'tax_bonus_minor',
                    ] as $field) {
                        $row[$field] = (int) $values[$field];
                    }
                    $row['insurance_days'] = $wage->insuranceDays;
                    $row['excluded_days'] = $wage->excludedDays;
                    $row['worked_days_hundredths'] = $wage->workedDaysHundredths;
                    $row['worked_minutes'] = $wage->workedMinutes;
                    $row['pension_participation'] = $wage->pensionParticipation;
                    $row['payout_date'] = $wage->payoutDate;
                }
                // Veličiny, které nese jen počáteční stav (základ daně, slevy,
                // dopočet do minima), jsou za osobu — do formuláře se vrací
                // u prvního vztahu měsíce, ostatní vztahy je mají nulové.
                if (!isset($firstByMonth[$month])) {
                    $firstByMonth[$month] = true;
                    $source = $openingRows[$month] ?? null;
                    if ($source !== null) {
                        $row['advance_base_minor'] = (int) ($source['advance_base_minor_units'] ?? 0);
                        $row['withholding_base_minor'] = (int) ($source['withholding_base_minor_units'] ?? 0);
                        $row['applied_credits_minor'] = (int) ($source['applied_non_refundable_credits_minor_units'] ?? 0);
                        $row['applied_child_credit_minor'] = (int) ($source['applied_child_credit_minor_units'] ?? 0);
                        $row['health_minimum_top_up_minor'] = (int) ($source['health_minimum_top_up_minor_units'] ?? 0);
                        if ($wage === null) {
                            // Měsíc zadaný jen v počátečních stavech: ukáže se, co tam je.
                            $row['social_base_minor'] = (int) ($source['social_assessment_base_minor_units'] ?? 0);
                            $row['health_base_minor'] = (int) ($source['health_assessment_base_minor_units'] ?? 0);
                            $row['employee_health_minor'] = (int) ($source['health_employee_contribution_minor_units'] ?? 0);
                            $row['employer_health_minor'] = (int) ($source['health_employer_contribution_minor_units'] ?? 0);
                            $row['advance_tax_minor'] = (int) ($source['advance_tax_minor_units'] ?? 0);
                            $row['withholding_tax_minor'] = (int) ($source['withholding_tax_minor_units'] ?? 0);
                            $row['tax_bonus_minor'] = (int) ($source['tax_bonus_minor_units'] ?? 0);
                        }
                    }
                }
                $row['stored'] = $wage !== null || isset($openingRows[$month]);
                $rows[] = $row;
            }
        }

        $partialLock = $opening['locked']
            && $this->openings->lockAllowsUnreportedChanges($supplierId, $employeeId, $year);

        return [
            'year' => $year,
            'employee_id' => $employeeId,
            'takeover_months' => $takeoverMonths,
            'employments' => array_map(
                static fn (array $employment): array => [
                    'id' => $employment['id'],
                    'code' => $employment['code'],
                    'relation_type' => $employment['relation_type'],
                    'start_date' => $employment['start_date'],
                    'end_date' => $employment['end_date'],
                    'months' => $employment['months'],
                ],
                $employments,
            ),
            'rows' => $rows,
            'source_reference' => (string) $opening['source_reference'],
            // Po podaném hlášení zůstává měsíc otevřený pro údaje, které hlášení
            // z převzatých úhrnů nevzalo (dny, odvody, čistá mzda). Co vzalo,
            // hlídá uložení přes PayrollOpeningBalanceService::lockReasonForChange().
            'locked' => $opening['locked'] && !$partialLock,
            'lock_reason' => $partialLock ? null : $opening['lock_reason'],
            'partial_lock_reason' => $partialLock ? $opening['lock_reason'] : null,
        ];
    }

    /**
     * Uloží převzaté měsíce osoby do obou vrstev.
     *
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed> aktuální stav formuláře
     */
    public function save(
        int $supplierId,
        int $employeeId,
        int $year,
        array $rows,
        string $sourceReference,
        ?int $actorUserId,
    ): array {
        $takeoverMonths = $this->coverage->takeoverMonths($supplierId, $year);
        if ($takeoverMonths === []) {
            throw new \InvalidArgumentException(sprintf(
                'Rok %d nemá převzaté měsíce: začátek vedení mezd v MyÚčtu v něm neleží. '
                . 'Nastavte první mzdové období v nastavení mezd.',
                $year,
            ));
        }
        $employments = [];
        foreach ($this->employments($supplierId, $employeeId, $year, $takeoverMonths) as $employment) {
            $employments[$employment['id']] = $employment;
        }
        if ($employments === []) {
            throw new \InvalidArgumentException(
                'Zaměstnanec nemá v převzatých měsících žádný trvající pracovní vztah.',
            );
        }

        $entered = [];
        foreach ($rows as $index => $raw) {
            $row = self::normalizeRow($raw, $index + 1);
            $employment = $employments[$row['employment_id']] ?? null;
            if ($employment === null) {
                throw new \InvalidArgumentException(sprintf(
                    'Řádek %d: pracovní vztah nepatří zaměstnanci nebo v převzatých měsících netrval.',
                    $index + 1,
                ));
            }
            if (!in_array($row['month'], $employment['months'], true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Řádek %d: vztah %s v měsíci %d netrval nebo měsíc už vede MyÚčto.',
                    $index + 1,
                    $employment['code'],
                    $row['month'],
                ));
            }
            $key = $row['employment_id'] . ':' . $row['month'];
            if (isset($entered[$key])) {
                throw new \InvalidArgumentException(sprintf(
                    'Měsíc %d vztahu %s je zadaný dvakrát.',
                    $row['month'],
                    $employment['code'],
                ));
            }
            if ($row['all_zero'] && !$row['confirmed_zero']) {
                throw new \InvalidArgumentException(sprintf(
                    'Měsíc %d vztahu %s nemá vyplněnou žádnou částku. Prázdný měsíc není nula — '
                    . 'vyplňte úhrny z předchozího programu, nebo potvrďte, že v něm opravdu nebyl příjem.',
                    $row['month'],
                    $employment['code'],
                ));
            }
            $entered[$key] = $row;
        }
        $missing = [];
        foreach ($employments as $employment) {
            foreach ($employment['months'] as $month) {
                if (!isset($entered[$employment['id'] . ':' . $month])) {
                    $missing[] = sprintf('%s: %d', $employment['code'], $month);
                }
            }
        }
        if ($missing !== []) {
            throw new \InvalidArgumentException(
                'Chybí převzaté měsíce, ve kterých vztah trval: ' . implode(', ', $missing)
                . '. Měsíc bez příjmu zadejte jako potvrzenou nulu.',
            );
        }

        $existing = $this->existingWages($supplierId, $employeeId, $year);
        $bySource = [];
        $openingMonths = [];
        foreach ($entered as $row) {
            $employment = $employments[$row['employment_id']];
            $current = $existing[$row['employment_id']][$row['month']] ?? null;
            $source = $current?->source ?? self::SOURCE;
            $bySource[$source][] = new PayrollMigrationReferenceTotals(
                sprintf('%04d-%02d', $year, $row['month']),
                $current?->externalPersonRef ?? ('employee:' . $employeeId),
                $current?->externalRelationshipRef ?? ('employment:' . $row['employment_id']),
                $employeeId,
                $row['employment_id'],
                $row['gross_minor'],
                $row['net_minor'],
                $row['social_base_minor'],
                $row['health_base_minor'],
                $row['employee_social_minor'],
                $row['employee_health_minor'],
                $row['employer_social_minor'],
                $row['employer_health_minor'],
                $row['advance_tax_minor'],
                $row['withholding_tax_minor'],
                $row['tax_bonus_minor'],
                new PayrollMigrationTakeoverFacts(
                    relationshipStartDate: $employment['start_date'],
                    relationshipEndDate: $employment['end_date'],
                    relationType: $employment['relation_type'],
                    activityCode: $this->activityCode($supplierId, $row['employment_id'], $year, $row['month']),
                    pensionParticipation: $row['pension_participation'],
                    insuranceDays: $row['insurance_days'],
                    excludedDays: $row['excluded_days'],
                    workedDaysHundredths: $row['worked_days_hundredths'],
                    workedMinutes: $row['worked_minutes'],
                    deductionsMinor: $row['deductions_minor'],
                    netPayableMinor: $row['net_payable_minor'],
                    payoutDate: $row['payout_date'],
                    // Ruční formulář tyhle veličiny nevede; převzaté z hlášení
                    // se úpravou měsíce nesmí tiše ztratit.
                    sicknessExcludedDays: $current?->sicknessExcludedDays,
                    uninsuredIncomeMinor: $current?->uninsuredIncomeMinor,
                    oldAgeSavingsContributionMinor: $row['old_age_savings_minor']
                        ?? $current?->oldAgeSavingsContributionMinor,
                    nonCashHealthBenefitMinor: $row['non_cash_health_benefit_minor']
                        ?? $current?->nonCashHealthBenefitMinor,
                    nonCashLeisureBenefitMinor: $row['non_cash_leisure_benefit_minor']
                        ?? $current?->nonCashLeisureBenefitMinor,
                ),
            );
            $month = $row['month'];
            $openingMonths[$month] ??= ['month' => $month, 'confirmed' => true];
            foreach (self::OPENING_MAP as $openingField => $rowField) {
                $openingMonths[$month][$openingField] = ($openingMonths[$month][$openingField] ?? 0) + $row[$rowField];
            }
            $openingMonths[$month]['confirmed'] = $openingMonths[$month]['confirmed']
                && (!$row['all_zero'] || $row['confirmed_zero']);
        }
        ksort($openingMonths);
        // Rozpis počátečního stavu musí být souvislý. Mezi dvěma vztahy s mezerou
        // žádný vztah netrval, takže nula je tam prokazatelná.
        $numbers = array_keys($openingMonths);
        for ($month = (int) min($numbers); $month <= (int) max($numbers); $month++) {
            $openingMonths[$month] ??= ['month' => $month, 'confirmed' => true];
            foreach (array_keys(self::OPENING_MAP) as $field) {
                $openingMonths[$month][$field] ??= 0;
            }
        }
        ksort($openingMonths);
        $opening = [];
        foreach ($openingMonths as $month) {
            $confirmed = $month['confirmed'];
            unset($month['confirmed']);
            if ($confirmed && OpeningBalanceMonthValidator::isAllZero($month)) {
                $month[OpeningBalanceMonthValidator::CONFIRMED_ZERO] = true;
            }
            $opening[] = $month;
        }

        $reference = trim($sourceReference) !== ''
            ? trim($sourceReference)
            : 'Ruční zadání převzatých mezd';
        $pdo = $this->db->pdo();
        $nested = $pdo->inTransaction();
        if ($nested) {
            $pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
        } else {
            $pdo->beginTransaction();
        }
        try {
            foreach ($bySource as $source => $totals) {
                $this->writer->store($supplierId, $source, $totals, 'manual-entry');
            }
            $this->openings->save($supplierId, $employeeId, $year, $opening, $reference, $actorUserId, true);
            if ($nested) {
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            } else {
                $pdo->commit();
            }
        } catch (\Throwable $exception) {
            if (TransactionFailure::rollbackIfAborted($pdo, $exception)) {
                throw $exception;
            }
            if ($nested) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            } elseif ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        return $this->form($supplierId, $employeeId, $year);
    }

    /**
     * @param mixed $raw
     * @return array<string,mixed>
     */
    private static function normalizeRow(mixed $raw, int $line): array
    {
        if (!is_array($raw)) {
            throw new \InvalidArgumentException("Řádek {$line} musí být objekt.");
        }
        $employmentId = filter_var($raw['employment_id'] ?? null, FILTER_VALIDATE_INT);
        $month = filter_var($raw['month'] ?? null, FILTER_VALIDATE_INT);
        if ($employmentId === false || $employmentId <= 0 || $month === false || $month < 1 || $month > 12) {
            throw new \InvalidArgumentException("Řádek {$line} nemá platný pracovní vztah nebo měsíc.");
        }
        $row = ['employment_id' => $employmentId, 'month' => $month];
        $allZero = true;
        foreach ([...self::MONEY_FIELDS, ...self::DURATION_FIELDS] as $field) {
            $value = filter_var($raw[$field] ?? 0, FILTER_VALIDATE_INT);
            if ($value === false || $value < 0) {
                throw new \InvalidArgumentException(sprintf(
                    'Řádek %d (měsíc %d): hodnota „%s" musí být nula nebo kladné číslo.',
                    $line,
                    $month,
                    $field,
                ));
            }
            $row[$field] = $value;
            if (in_array($field, self::MONEY_FIELDS, true) && $value !== 0) {
                $allZero = false;
            }
        }
        foreach (self::BENEFIT_FIELDS as $field) {
            if (!array_key_exists($field, $raw) || $raw[$field] === null) {
                $row[$field] = null;
                continue;
            }
            $value = filter_var($raw[$field], FILTER_VALIDATE_INT);
            if ($value === false || $value < 0) {
                throw new \InvalidArgumentException(sprintf(
                    'Řádek %d (měsíc %d): hodnota „%s" musí být nula nebo kladné číslo.',
                    $line,
                    $month,
                    $field,
                ));
            }
            $row[$field] = $value;
        }
        $row['all_zero'] = $allZero;
        $row['confirmed_zero'] = ($raw['confirmed_zero'] ?? false) === true;
        $row['pension_participation'] = ($raw['pension_participation'] ?? true) !== false;
        $payout = $raw['payout_date'] ?? null;
        $row['payout_date'] = is_string($payout) && trim($payout) !== '' ? trim($payout) : null;

        return $row;
    }

    /**
     * Vztahy osoby, které v převzatých měsících trvaly, s těmi měsíci.
     *
     * @param list<int> $takeoverMonths
     * @return list<array{id:int,code:string,relation_type:?string,start_date:?string,end_date:?string,months:list<int>}>
     */
    private function employments(int $supplierId, int $employeeId, int $year, array $takeoverMonths): array
    {
        if ($takeoverMonths === []) {
            return [];
        }
        $statement = $this->db->pdo()->prepare(sprintf(
            'SELECT id, code, relation_type, start_date, end_date
               FROM payroll_employments
              WHERE supplier_id = ? AND employee_id = ?
                AND status IN (%s)
              ORDER BY is_primary DESC, start_date, id',
            implode(', ', array_fill(0, count(PayrollEmploymentMonths::STATUSES), '?')),
        ));
        $statement->execute([$supplierId, $employeeId, ...PayrollEmploymentMonths::STATUSES]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $months = array_values(array_intersect(
                PayrollEmploymentMonths::covered([$row], $year),
                $takeoverMonths,
            ));
            if ($months === []) {
                continue;
            }
            $result[] = [
                'id' => (int) $row['id'],
                'code' => (string) $row['code'],
                'relation_type' => is_string($row['relation_type'] ?? null) ? $row['relation_type'] : null,
                'start_date' => is_string($row['start_date'] ?? null) ? substr($row['start_date'], 0, 10) : null,
                'end_date' => is_string($row['end_date'] ?? null) ? substr($row['end_date'], 0, 10) : null,
                'months' => $months,
            ];
        }

        return $result;
    }

    /**
     * Převzaté mzdy osoby podle vztahu a měsíce. Má-li měsíc víc zdrojů, bere
     * se první — kontrola převzetí druhý zdroj ukáže jako rozdíl.
     *
     * @return array<int,array<int,PayrollTakeoverMonth>>
     */
    private function existingWages(int $supplierId, int $employeeId, int $year): array
    {
        $result = [];
        foreach ($this->reader->forEmployee($supplierId, $employeeId, $year)->months as $month) {
            if ($month->employmentId === null) {
                continue;
            }
            $number = (int) substr($month->period, 5, 2);
            $result[$month->employmentId][$number] ??= $month;
        }

        return $result;
    }

    private function activityCode(int $supplierId, int $employmentId, int $year, int $month): ?string
    {
        $monthStart = sprintf('%04d-%02d-01', $year, $month);
        $statement = $this->db->pdo()->prepare(
            'SELECT activity_code
               FROM payroll_employment_terms
              WHERE supplier_id = ? AND employment_id = ?
                AND effective_from <= LAST_DAY(?)
                AND (effective_to IS NULL OR effective_to >= ?)
              ORDER BY effective_from DESC, id DESC
              LIMIT 1',
        );
        $statement->execute([$supplierId, $employmentId, $monthStart, $monthStart]);
        $value = $statement->fetchColumn();

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
