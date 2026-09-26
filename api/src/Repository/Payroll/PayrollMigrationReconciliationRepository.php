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

    public function hasTakeoverCollision(int $supplierId, int $employmentId, string $source, string $period, string $relationshipRef): bool
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT 1 FROM payroll_migration_reference_totals
              WHERE supplier_id = ? AND period_start = ?
                AND (employment_id = ? OR (source = ? AND external_relationship_ref = ?)) LIMIT 1',
        );
        $statement->execute([$supplierId, $period . '-01', $employmentId, $source, $relationshipRef]);
        return $statement->fetchColumn() !== false;
    }

    /**
     * Převzaté úhrny, granularita pracovní vztah × měsíc.
     *
     * @return list<array<string,mixed>>
     */
    public function referenceTotals(int $supplierId, int $year, ?string $source = null): array
    {
        $sql = 'SELECT DATE_FORMAT(period_start, "%Y-%m") AS period,
                       source, external_person_ref, external_relationship_ref,
                       employee_id, employment_id,
                       gross_minor, net_minor, social_base_minor, health_base_minor,
                       employee_social_minor, employee_health_minor,
                       employer_social_minor, employer_health_minor,
                       advance_tax_minor, withholding_tax_minor, tax_bonus_minor
                  FROM payroll_migration_reference_totals
                 WHERE supplier_id = ?
                   AND period_start >= ?
                   AND period_start < ?';
        $parameters = [$supplierId, sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1)];
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
                       payout_date, import_reference
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
                  JOIN payroll_net_results net
                    ON net.supplier_id = revision.supplier_id
                   AND net.revision_id = revision.id
                 WHERE run.supplier_id = ?
                   AND run.period_start >= ?
                   AND run.period_start < ?';
        $parameters = [$supplierId, sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1)];
        if ($employeeId !== null) {
            $sql .= ' AND net.employee_id = ?';
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
     * Hrubá mzda a čistá mzda se berou z `payroll_net_results` — z téhož neměnného
     * výsledku, který vydal výplatní pásku. Čistá mzda je PŘED srážkami
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
                    net.employee_id,
                    employee.full_name,
                    net.cash_income_minor + net.non_cash_income_minor AS gross_minor,
                    net.net_payable_minor + net.deducted_minor AS net_minor,
                    JSON_VALUE(social.result_snapshot_json, "$.capped_assessment_base_minor_units")
                        AS social_base_minor,
                    JSON_VALUE(health.result_snapshot_json, "$.assessment_base_minor_units")
                        AS health_base_minor,
                    net.employee_social_minor,
                    net.employee_health_minor,
                    JSON_VALUE(health.result_snapshot_json, "$.employer_contribution_minor_units")
                        AS employer_health_minor,
                    net.advance_tax_minor,
                    net.withholding_tax_minor,
                    net.tax_bonus_minor
               FROM payroll_runs run
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = run.supplier_id
                AND revision.run_id = run.id
                AND revision.revision_no = run.current_revision_no
               JOIN payroll_net_results net
                 ON net.supplier_id = revision.supplier_id
                AND net.revision_id = revision.id
               JOIN payroll_employees employee
                 ON employee.supplier_id = net.supplier_id
                AND employee.id = net.employee_id
          LEFT JOIN payroll_statutory_person_results social
                 ON social.supplier_id = revision.supplier_id
                AND social.revision_id = revision.id
                AND social.employee_id = net.employee_id
                AND social.calculation_kind = "social_insurance"
                AND social.result_status = "calculated"
          LEFT JOIN payroll_statutory_person_results health
                 ON health.supplier_id = revision.supplier_id
                AND health.revision_id = revision.id
                AND health.employee_id = net.employee_id
                AND health.calculation_kind = "health_insurance"
                AND health.result_status = "calculated"
              WHERE run.supplier_id = ?
                AND run.period_start >= ?
                AND run.period_start < ?
              ORDER BY run.period_start, net.employee_id',
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
