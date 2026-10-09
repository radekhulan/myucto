<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Obě strany kontrolní sestavy „naše přepočtená mzda vs. mzda převzatá z původního
 * systému" ({@see \MyInvoice\Service\Payroll\Report\PayrollMigrationReconciliationBuilder}).
 *
 * Čte se AKTUÁLNÍ revize běhu, ne jen schválená: smysl sestavy je podívat se na
 * přepočet DŘÍV, než ho účetní schválí. Stav revize jde ven s měsícem, aby bylo
 * na obrazovce vidět, že se porovnává něco rozpracovaného.
 */
final class PayrollMigrationReconciliationRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @return array<string,mixed>|null Přesný cíl mapy převodu, včetně identity vztahu. */
    public function takeoverById(int $supplierId, int $id, string $source): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, employee_id, employment_id, period_start, external_person_ref, external_relationship_ref
               FROM payroll_migration_reference_totals
              WHERE supplier_id = ? AND id = ? AND source = ?',
        );
        $statement->execute([$supplierId, $id, $source]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function takeoverId(int $supplierId, string $source, string $period, string $relationshipRef): ?int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_migration_reference_totals
              WHERE supplier_id = ? AND source = ? AND period_start = ? AND external_relationship_ref = ?',
        );
        $statement->execute([$supplierId, $source, $period . '-01', $relationshipRef]);
        $id = $statement->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** Zdroj, ze kterého už je měsíc u vztahu převzatý (vlastní zdroj má přednost), jinak null. */
    public function takeoverCollisionSource(int $supplierId, int $employmentId, string $source, string $period, string $relationshipRef): ?string
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT source FROM payroll_migration_reference_totals
              WHERE supplier_id = ? AND period_start = ?
                AND (employment_id = ? OR (source = ? AND external_relationship_ref = ?))
              ORDER BY source = ? DESC LIMIT 1',
        );
        $statement->execute([$supplierId, $period . '-01', $employmentId, $source, $relationshipRef, $source]);
        $found = $statement->fetchColumn();
        return $found === false ? null : (string) $found;
    }

    /**
     * Převzaté úhrny, granularita pracovní vztah × měsíc.
     *
     * @return list<array<string,mixed>>
     */
    public function referenceTotals(int $supplierId, int $year, ?string $source = null): array
    {
        // Jméno osoby (Q15-35): bez něj se řádek bez protějšku ukazoval jen
        // jako „Osoba 1878 z původního systému".
        $sql = 'SELECT DATE_FORMAT(totals.period_start, "%Y-%m") AS period,
                       totals.source, totals.external_person_ref, totals.external_relationship_ref,
                       totals.employee_id, totals.employment_id, employee.full_name,
                       totals.gross_minor, totals.net_minor, totals.social_base_minor, totals.health_base_minor,
                       totals.employee_social_minor, totals.employee_health_minor,
                       totals.employer_social_minor, totals.employer_health_minor,
                       totals.advance_tax_minor, totals.withholding_tax_minor, totals.tax_bonus_minor
                  FROM payroll_migration_reference_totals totals
             LEFT JOIN payroll_employees employee
                    ON employee.supplier_id = totals.supplier_id AND employee.id = totals.employee_id
                 WHERE totals.supplier_id = ?
                   AND totals.period_start >= ?
                   AND totals.period_start < ?';
        $parameters = [$supplierId, sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1)];
        if ($source !== null) {
            $sql .= ' AND totals.source = ?';
            $parameters[] = $source;
        }
        $sql .= ' ORDER BY totals.period_start, totals.external_person_ref, totals.external_relationship_ref';

        $statement = $this->db->pdo()->prepare($sql);
        $statement->execute($parameters);

        /** @var list<array<string,mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    }

    /**
     * Úplné převzaté řádky včetně dob, druhu vztahu a platby (migrace 1851).
     *
     * Na rozdíl od {@see self::referenceTotals()}, které vydává jen porovnávané
     * peníze pro kontrolní sestavu, tohle je celý řádek — čte ho
     * {@see \MyInvoice\Service\Payroll\Migration\PayrollTakeoverReader} a nad ním
     * stojí sestavení ELDP i zpětná evidence plateb za rok přechodu.
     *
     * Filtr podle osoby i vztahu je volitelný a kombinovatelný; `employment_id`
     * je soft link, takže vztah, který se do MyÚčta nepřevedl, se dá najít jen
     * přes osobu nebo celofiremním čtením.
     *
     * @return list<array<string,mixed>>
     */
    public function takeoverRows(
        int $supplierId,
        int $year,
        ?int $employeeId = null,
        ?int $employmentId = null,
        ?string $source = null,
    ): array {
        $sql = 'SELECT DATE_FORMAT(period_start, "%Y-%m") AS period,
                       source, external_person_ref, external_relationship_ref,
                       employee_id, employment_id,
                       relationship_start_date, relationship_end_date,
                       relation_type, activity_code, pension_participation,
                       insurance_days, excluded_days,
                       worked_days_hundredths, worked_minutes,
                       gross_minor, net_minor, deductions_minor, net_payable_minor,
                       social_base_minor, health_base_minor,
                       employee_social_minor, employee_health_minor,
                       employer_social_minor, employer_health_minor,
                       advance_tax_minor, withholding_tax_minor, tax_bonus_minor,
                       payout_date, import_reference,
                       sickness_excluded_days, uninsured_income_minor
                  FROM payroll_migration_reference_totals
                 WHERE supplier_id = ?
                   AND period_start >= ?
                   AND period_start < ?';
        $parameters = [$supplierId, sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1)];
        if ($employeeId !== null) {
            $sql .= ' AND employee_id = ?';
            $parameters[] = $employeeId;
        }
        if ($employmentId !== null) {
            $sql .= ' AND employment_id = ?';
            $parameters[] = $employmentId;
        }
        if ($source !== null) {
            $sql .= ' AND source = ?';
            $parameters[] = $source;
        }
        $sql .= ' ORDER BY period_start, external_person_ref, external_relationship_ref';

        $statement = $this->db->pdo()->prepare($sql);
        $statement->execute($parameters);

        /** @var list<array<string,mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    }

    /**
     * První doložený měsíc trvání pracovního vztahu po konci roku.
     *
     * Prázdné skončení vztahu u převzatých měsíců znamená „trvá" i „původní
     * program ho nevydal". Doklad, že vztah 31. 12. neskončil, je měsíc
     * následujícího období, ve kterém vztah prokazatelně běží:
     *
     *  - převzatý měsíc téhož vztahu se skončením po konci roku, nebo bez
     *    skončení, ale s odpracovanou dobou či dobou pojištění (samotný
     *    dodatečně zúčtovaný příjem po skončení trvání nedokládá),
     *  - vztah ve schválené mzdové revizi, jejíž zmrazený vztah nekončí
     *    do konce roku (revize po skončení nese vztah jen s datem skončení).
     *
     * Vrací se nejdřívější z obou; datum nástupu z dokladu si ověří ten, kdo
     * trvání skládá.
     *
     * @return array{period:string,source:string,relationship_start_date:?string,relationship_end_date:?string}|null
     */
    public function employmentContinuationAfterYear(int $supplierId, int $employmentId, int $year): ?array
    {
        $nextYear = sprintf('%04d-01-01', $year + 1);
        $yearEnd = sprintf('%04d-12-31', $year);
        $takeover = $this->db->pdo()->prepare(
            'SELECT DATE_FORMAT(period_start, "%Y-%m") AS period,
                    relationship_start_date, relationship_end_date
               FROM payroll_migration_reference_totals
              WHERE supplier_id = ? AND employment_id = ? AND period_start >= ?
                AND (relationship_end_date > ?
                     OR (relationship_end_date IS NULL
                         AND (insurance_days > 0 OR worked_days_hundredths > 0 OR worked_minutes > 0)))
              ORDER BY period_start
              LIMIT 1',
        );
        $takeover->execute([$supplierId, $employmentId, $nextYear, $yearEnd]);
        $takeoverRow = $takeover->fetch(PDO::FETCH_ASSOC);

        $calculated = $this->db->pdo()->prepare(
            'SELECT DATE_FORMAT(employment.period_start, "%Y-%m") AS period,
                    COALESCE(JSON_VALUE(employment.input_json, "$.employment.actual_start_date"),
                             JSON_VALUE(employment.input_json, "$.employment.start_date")) AS relationship_start_date,
                    JSON_VALUE(employment.input_json, "$.employment.end_date") AS relationship_end_date
               FROM payroll_run_employments employment
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = employment.supplier_id
                AND revision.id = employment.revision_id
                AND revision.status = "approved"
              WHERE employment.supplier_id = ? AND employment.employment_id = ?
                AND employment.period_start >= ?
                AND (JSON_VALUE(employment.input_json, "$.employment.end_date") IS NULL
                     OR JSON_VALUE(employment.input_json, "$.employment.end_date") > ?)
              ORDER BY employment.period_start
              LIMIT 1',
        );
        $calculated->execute([$supplierId, $employmentId, $nextYear, $yearEnd]);
        $calculatedRow = $calculated->fetch(PDO::FETCH_ASSOC);

        $candidates = [];
        if (is_array($takeoverRow)) {
            $candidates[] = ['source' => 'takeover', ...$takeoverRow];
        }
        if (is_array($calculatedRow)) {
            $candidates[] = ['source' => 'calculated', ...$calculatedRow];
        }
        $first = null;
        foreach ($candidates as $candidate) {
            if ($first === null || (string) $candidate['period'] < $first['period']) {
                $first = [
                    'period' => (string) $candidate['period'],
                    'source' => $candidate['source'],
                    'relationship_start_date' => $candidate['relationship_start_date'] === null
                        ? null : (string) $candidate['relationship_start_date'],
                    'relationship_end_date' => $candidate['relationship_end_date'] === null
                        ? null : (string) $candidate['relationship_end_date'],
                ];
            }
        }

        return $first;
    }

    /**
     * Schválené nepřítomnosti pracovního vztahu, které zasahují do roku, ve
     * tvaru zmrazeného vstupu mzdového běhu (jen klíče, které čte odvození
     * vyloučených dob evidenčního listu).
     *
     * @return list<array<string,mixed>>
     */
    public function approvedAbsences(int $supplierId, int $employmentId, int $year): array
    {
        $statement = $this->db->pdo()->prepare(
            "SELECT id, absence_type, date_from, date_to, expected_childbirth_date, childbirth_date,
                    lone_carer, partial_first_minutes
               FROM payroll_absences
              WHERE supplier_id = ? AND employment_id = ? AND status = 'approved'
                AND date_from <= ? AND date_to >= ?
              ORDER BY date_from, id",
        );
        $statement->execute([$supplierId, $employmentId, sprintf('%04d-12-31', $year), sprintf('%04d-01-01', $year)]);
        $absences = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $type = (string) $row['absence_type'];
            $absences[] = [
                ...($type === 'ppm' ? [
                    'expected_childbirth_date' => $row['expected_childbirth_date'] === null ? null : (string) $row['expected_childbirth_date'],
                    'childbirth_date' => $row['childbirth_date'] === null ? null : (string) $row['childbirth_date'],
                ] : []),
                ...($type === 'ocr' ? ['lone_carer' => (int) ($row['lone_carer'] ?? 0) === 1] : []),
                'id' => (int) $row['id'],
                'absence_type' => $type,
                'date_from' => (string) $row['date_from'],
                'date_to' => (string) $row['date_to'],
                'partial_first_minutes' => $row['partial_first_minutes'] === null ? null : (int) $row['partial_first_minutes'],
            ];
        }

        return $absences;
    }

    /**
     * Období roku, za která MyÚčto samo počítalo mzdu.
     *
     * Čte se AKTUÁLNÍ revize, ne jen schválená — stejně jako
     * {@see self::calculatedTotals()}. Rozpracovaný běh je taky „MyÚčto ten
     * měsíc počítá" a navazující sestava musí vidět, že proti převzatému měsíci
     * stojí ještě neschválený přepočet.
     *
     * S `$employeeId` jsou to období, ve kterých má vlastní výsledek TA osoba;
     * bez něj kterákoli osoba ve firmě.
     *
     * Výsledek osoby je `payroll_run_persons` se stavem `calculated`, ne
     * `payroll_net_results`: ta tabulka se do pipeline nikdy nezapojila a je
     * prázdná (viz {@see PayrollNetRepository}). Dokud se četla, MyÚčto nevidělo
     * jediný svůj spočítaný měsíc a rok přechodu je hlásil jako díry.
     *
     * @return list<string> `YYYY-MM`, vzestupně
     */
    public function calculatedPeriods(int $supplierId, int $year, ?int $employeeId = null): array
    {
        $sql = 'SELECT DISTINCT DATE_FORMAT(run.period_start, "%Y-%m") AS period
                  FROM payroll_runs run
                  JOIN payroll_run_revisions revision
                    ON revision.supplier_id = run.supplier_id
                   AND revision.run_id = run.id
                   AND revision.revision_no = run.current_revision_no
                  JOIN payroll_run_persons person
                    ON person.supplier_id = revision.supplier_id
                   AND person.revision_id = revision.id
                   AND person.status = "calculated"
                 WHERE run.supplier_id = ?
                   AND run.period_start >= ?
                   AND run.period_start < ?';
        $parameters = [$supplierId, sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1)];
        if ($employeeId !== null) {
            $sql .= ' AND person.employee_id = ?';
            $parameters[] = $employeeId;
        }
        $sql .= ' ORDER BY period';

        $statement = $this->db->pdo()->prepare($sql);
        $statement->execute($parameters);

        return array_map('strval', (array) $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<string> zdroje, ze kterých pro firmu a rok něco leží */
    public function referenceSources(int $supplierId, int $year): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT DISTINCT source
               FROM payroll_migration_reference_totals
              WHERE supplier_id = ? AND period_start >= ? AND period_start < ?
              ORDER BY source',
        );
        $statement->execute([$supplierId, sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1)]);

        return array_map('strval', (array) $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Náš výsledek, granularita osoba × měsíc.
     *
     * Hrubá mzda a čistá mzda se berou z rozkladu čisté mzdy ve výsledku osoby
     * (`payroll_run_persons.result_json`, klíč `statutory.net_pay`) — z téhož
     * neměnného výsledku, který vydal výplatní pásku. Čistá mzda je PŘED srážkami
     * (`net_payable + deducted`), protože právě tu vydává i původní systém v `KcCistaM`;
     * porovnávat částku po srážkách proti částce před nimi by vyrobilo rozdíl,
     * který s přepočtem nemá nic společného.
     *
     * Vyměřovací základy a pojistné zaměstnavatele na zdravotní jsou v osobních
     * výsledcích zákonných výpočtů. Když osobní výsledek chybí (starší revize),
     * vrátí se `NULL` a sestava to ukáže jako chybějící protějšek, ne jako nulu.
     *
     * @return list<array<string,mixed>>
     */
    public function calculatedTotals(int $supplierId, int $year): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT DATE_FORMAT(run.period_start, "%Y-%m") AS period,
                    revision.id AS revision_id,
                    revision.status AS revision_status,
                    person.employee_id,
                    employee.full_name,
                    CAST(JSON_VALUE(person.result_json, "$.statutory.net_pay.cash_income_minor_units") AS SIGNED)
                        + CAST(JSON_VALUE(person.result_json, "$.statutory.net_pay.non_cash_income_minor_units") AS SIGNED)
                        AS gross_minor,
                    CAST(JSON_VALUE(person.result_json, "$.statutory.net_pay.net_payable_minor_units") AS SIGNED)
                        + CAST(JSON_VALUE(person.result_json, "$.statutory.net_pay.deducted_minor_units") AS SIGNED)
                        AS net_minor,
                    JSON_VALUE(social.result_snapshot_json, "$.capped_assessment_base_minor_units")
                        AS social_base_minor,
                    JSON_VALUE(health.result_snapshot_json, "$.assessment_base_minor_units")
                        AS health_base_minor,
                    JSON_VALUE(person.result_json, "$.statutory.net_pay.employee_social_minor_units")
                        AS employee_social_minor,
                    JSON_VALUE(person.result_json, "$.statutory.net_pay.employee_health_minor_units")
                        AS employee_health_minor,
                    JSON_VALUE(health.result_snapshot_json, "$.employer_contribution_minor_units")
                        AS employer_health_minor,
                    JSON_VALUE(person.result_json, "$.statutory.net_pay.advance_tax_minor_units")
                        AS advance_tax_minor,
                    JSON_VALUE(person.result_json, "$.statutory.net_pay.withholding_tax_minor_units")
                        AS withholding_tax_minor,
                    JSON_VALUE(person.result_json, "$.statutory.net_pay.tax_bonus_minor_units")
                        AS tax_bonus_minor
               FROM payroll_runs run
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = run.supplier_id
                AND revision.run_id = run.id
                AND revision.revision_no = run.current_revision_no
               JOIN payroll_run_persons person
                 ON person.supplier_id = revision.supplier_id
                AND person.revision_id = revision.id
                AND person.status = "calculated"
               JOIN payroll_employees employee
                 ON employee.supplier_id = person.supplier_id
                AND employee.id = person.employee_id
          LEFT JOIN payroll_statutory_person_results social
                 ON social.supplier_id = revision.supplier_id
                AND social.revision_id = revision.id
                AND social.employee_id = person.employee_id
                AND social.calculation_kind = "social_insurance"
                AND social.result_status = "calculated"
          LEFT JOIN payroll_statutory_person_results health
                 ON health.supplier_id = revision.supplier_id
                AND health.revision_id = revision.id
                AND health.employee_id = person.employee_id
                AND health.calculation_kind = "health_insurance"
                AND health.result_status = "calculated"
              WHERE run.supplier_id = ?
                AND run.period_start >= ?
                AND run.period_start < ?
              ORDER BY run.period_start, person.employee_id',
        );
        $statement->execute([$supplierId, sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1)]);

        /** @var list<array<string,mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    }

    /**
     * Pojistné zaměstnavatele na sociální zabezpečení za měsíc a firmu.
     * Osobní veličina to není (§ 5a odst. 1 z. č. 589/1992 Sb.), proto jen souhrn.
     *
     * @return array<string,int|null> období => haléře
     */
    public function calculatedEmployerSocial(int $supplierId, int $year): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT DATE_FORMAT(run.period_start, "%Y-%m") AS period,
                    JSON_VALUE(social.result_snapshot_json, "$.employer_contribution_minor_units")
                        AS employer_social_minor
               FROM payroll_runs run
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = run.supplier_id
                AND revision.run_id = run.id
                AND revision.revision_no = run.current_revision_no
          LEFT JOIN payroll_statutory_results social
                 ON social.supplier_id = revision.supplier_id
                AND social.revision_id = revision.id
                AND social.calculation_kind = "social_insurance"
                AND social.result_status = "calculated"
              WHERE run.supplier_id = ?
                AND run.period_start >= ?
                AND run.period_start < ?
              ORDER BY run.period_start',
        );
        $statement->execute([$supplierId, sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1)]);

        $totals = [];
        /** @var array<string,mixed> $row */
        foreach ((array) $statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $period = (string) $row['period'];
            $value = $row['employer_social_minor'] ?? null;
            $amount = is_numeric($value) ? (int) $value : null;
            if (!array_key_exists($period, $totals)) {
                $totals[$period] = $amount;
                continue;
            }
            // Víc běhů v jednom měsíci (provozovny): chybějící výsledek jedné
            // z nich nesmí zmizet v součtu ostatních.
            $totals[$period] = $totals[$period] === null || $amount === null
                ? null
                : $totals[$period] + $amount;
        }

        return $totals;
    }
}
