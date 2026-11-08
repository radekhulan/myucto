<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time\Juvenile;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Run\PayrollRunValidation;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Time\Overtime\OvertimeEmploymentProfile;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeRuleset;
use PDO;

/**
 * Podklady pro {@see JuvenileWorkTimeEvaluator}: mladiství mezi vztahy stránky
 * a jejich skutečně odpracované záznamy ze VŠECH vztahů u zaměstnavatele.
 *
 * Nejdřív se jedním dotazem zjistí, kdo z osob může být v měsíci mladistvý;
 * firma bez mladistvých tak záznamy vůbec nečte. Záznamy se berou za celé
 * týdny, které měsíc protínají (týdenní limit), a o den navíc kvůli
 * odpočinku před první směnou měsíce. Záznamy měsíce, který bere hodiny
 * z ručního nebo importovaného souhrnu, jsou jen uchovaná evidence a do
 * kontroly nejdou.
 *
 * Nálezy jsou varování: v docházce u zaměstnance a v kontrole mzdového běhu,
 * bez výjimky k odklepnutí. Mzdu neovlivňují.
 */
final class PayrollJuvenileWorkTimeService
{
    public function __construct(
        private readonly Connection $db,
        private readonly PayrollRulesetProvider $rulesets,
    ) {}

    /**
     * @param list<int> $employmentIds
     * @return array<int,list<JuvenileWorkTimeFinding>> nálezy osoby u každého jejího vztahu
     */
    public function findingsForMany(int $supplierId, array $employmentIds, string $periodStart): array
    {
        if ($employmentIds === []) {
            return [];
        }
        $monthFirst = new \DateTimeImmutable(substr($periodStart, 0, 7) . '-01');
        $monthLast = $monthFirst->modify('last day of this month');
        // Mladistvý aspoň jeden den měsíce = 18. narozeniny až po prvním dni měsíce.
        $bornAfter = $monthFirst->modify('-18 years')->format('Y-m-d');
        $in = implode(',', array_fill(0, count($employmentIds), '?'));
        $people = $this->db->pdo()->prepare(
            "SELECT employment.id AS employment_id, employment.employee_id, employee.birth_date
               FROM payroll_employments employment
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id AND employee.id = employment.employee_id
              WHERE employment.supplier_id = ? AND employment.id IN ($in)
                AND employee.birth_date IS NOT NULL AND employee.birth_date > ?"
        );
        $people->execute([$supplierId, ...$employmentIds, $bornAfter]);
        $byEmployee = [];
        foreach ($people->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byEmployee[(int) $row['employee_id']]['birth_date'] = (string) $row['birth_date'];
            $byEmployee[(int) $row['employee_id']]['employment_ids'][] = (int) $row['employment_id'];
        }
        if ($byEmployee === []) {
            return [];
        }

        $windowFrom = $monthFirst->modify('monday this week')->modify('-1 day');
        $windowTo = $monthLast->modify('sunday this week')->modify('+2 days');
        $employees = implode(',', array_fill(0, count($byEmployee), '?'));
        $entries = $this->db->pdo()->prepare(
            "SELECT entry.employment_id, employment.employee_id, entry.starts_at_utc, entry.ends_at_utc,
                    entry.break_minutes, entry.timezone_name
               FROM payroll_employments employment
               JOIN payroll_time_entries entry
                 ON entry.supplier_id = employment.supplier_id AND entry.employment_id = employment.id
              WHERE employment.supplier_id = ? AND employment.employee_id IN ($employees)
                AND entry.starts_at_utc >= ? AND entry.starts_at_utc < ?
                AND entry.status <> 'superseded' AND entry.category IN ('regular', 'overtime')"
        );
        $entries->execute([
            $supplierId, ...array_keys($byEmployee),
            $windowFrom->format('Y-m-d H:i:s'), $windowTo->format('Y-m-d H:i:s'),
        ]);
        $rows = $entries->fetchAll(PDO::FETCH_ASSOC);
        $sources = $this->summaryMonths($supplierId, array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['employment_id'],
            $rows,
        ))), $windowFrom->format('Y-m-01'), $windowTo->format('Y-m-01'));

        $byPerson = [];
        foreach ($rows as $row) {
            $timezone = new \DateTimeZone((string) $row['timezone_name']);
            $localMonth = (new \DateTimeImmutable((string) $row['starts_at_utc'], new \DateTimeZone('UTC')))
                ->setTimezone($timezone)->format('Y-m-01');
            if (isset($sources[(int) $row['employment_id']][$localMonth])) {
                continue;
            }
            $byPerson[(int) $row['employee_id']][] = [
                'starts_at_utc' => (string) $row['starts_at_utc'],
                'ends_at_utc' => (string) $row['ends_at_utc'],
                'break_minutes' => (int) $row['break_minutes'],
                'timezone_name' => (string) $row['timezone_name'],
            ];
        }

        // Noční doba je táž jako u příplatku za noc; bez sady pravidel pro
        // období platí zákonných 22 až 6 h (§ 78 odst. 1 zákoníku práce).
        try {
            $ruleset = PayrollSurchargeRuleset::forDate($this->rulesets, $monthFirst->format('Y-m-d'));
            $evaluator = new JuvenileWorkTimeEvaluator($ruleset->nightWindowStartHour(), $ruleset->nightWindowEndHour());
        } catch (\RuntimeException | \LogicException) {
            $evaluator = new JuvenileWorkTimeEvaluator();
        }
        $result = [];
        foreach ($byEmployee as $employeeId => $person) {
            $findings = $evaluator->evaluate(
                $byPerson[$employeeId] ?? [],
                new OvertimeEmploymentProfile($person['birth_date']),
                $monthFirst->format('Y-m-d'),
            );
            if ($findings === []) {
                continue;
            }
            foreach ($person['employment_ids'] as $employmentId) {
                $result[$employmentId] = $findings;
            }
        }

        return $result;
    }

    /**
     * Varování do kontroly mzdového běhu. Osoba s více vztahy v běhu dostane
     * nálezy jen jednou, u vztahu s nejnižším ID: doba se sčítá přes vztahy
     * a opakovaná věta u každého by jen přidala šum.
     *
     * @param list<array<string,mixed>> $employments řádky běhu s `employment_id` a `employee_id`
     * @return list<PayrollRunValidation>
     */
    public function validations(int $supplierId, array $employments, string $periodStart): array
    {
        $ids = array_map(static fn (array $row): int => (int) $row['employment_id'], $employments);
        $findings = $this->findingsForMany($supplierId, $ids, $periodStart);
        if ($findings === []) {
            return [];
        }
        $owner = [];
        foreach ($employments as $row) {
            $employeeId = (int) ($row['employee_id'] ?? 0);
            $employmentId = (int) $row['employment_id'];
            $owner[$employeeId] = min($owner[$employeeId] ?? PHP_INT_MAX, $employmentId);
        }
        $validations = [];
        foreach ($owner as $employmentId) {
            foreach ($findings[$employmentId] ?? [] as $finding) {
                $validations[] = new PayrollRunValidation(
                    'warning',
                    $finding->code,
                    'employment',
                    $employmentId,
                    $finding->message,
                    '/payroll/time?period=' . substr($periodStart, 0, 7) . '&employment=' . $employmentId,
                );
            }
        }

        return $validations;
    }

    /**
     * Měsíce vztahů, které berou hodiny z ručního nebo importovaného souhrnu.
     *
     * @param list<int> $employmentIds
     * @return array<int,array<string,true>>
     */
    private function summaryMonths(int $supplierId, array $employmentIds, string $from, string $to): array
    {
        if ($employmentIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($employmentIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT employment_id, period_start FROM payroll_time_months
              WHERE supplier_id = ? AND employment_id IN ($in) AND period_start >= ? AND period_start <= ?
                AND work_source <> 'entries'"
        );
        $stmt->execute([$supplierId, ...$employmentIds, $from, $to]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['employment_id']][(string) $row['period_start']] = true;
        }

        return $result;
    }
}
