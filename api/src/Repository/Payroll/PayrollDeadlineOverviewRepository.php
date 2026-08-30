<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Podklady pro přehled mzdových termínů.
 *
 * Modul uměl lhůty SPOČÍTAT ({@see \MyInvoice\Service\Payroll\Deadline\PayrollLevyDeadlinePolicy},
 * lhůty podání), ale nikomu je neřekl: nebyl žádný dotaz, který by za firmu
 * vrátil „co je do kdy a co je po termínu". Přehled se skládal ručně z tří
 * různých obrazovek, každá za jiné období, a zmeškaný termín se nikde
 * nezvýraznil.
 *
 * Repozitář vrací HOLÁ DATA ze tří pramenů; o tom, co je „brzy" a co „po
 * termínu", rozhoduje až
 * {@see \MyInvoice\Service\Payroll\Deadline\PayrollDeadlineOverviewService}
 * proti hodinám — aby se to dalo otestovat bez posouvání systémového času.
 */
final readonly class PayrollDeadlineOverviewRepository
{
    /**
     * Druhy závazků, které jsou ODVODEM se zákonnou splatností. Čistá mzda,
     * srážky ani exekuce sem nepatří: jejich termín plyne ze smlouvy nebo
     * z rozhodnutí, ne ze zákonné lhůty odvodu, a promíchat je do jednoho
     * seznamu by z přehledu udělalo výpis všech plateb.
     */
    private const LEVY_KINDS = [
        'social_insurance',
        'health_insurance',
        'advance_tax',
        'withholding_tax',
        'statutory_insurance',
    ];

    public function __construct(private Connection $db) {}

    /**
     * Nesplněné povinnosti podání s termínem v okně.
     *
     * @return list<array{
     *   obligation_id:int,agenda_code:string,subject_type:string,
     *   subject_reference:string,period_start:string,period_end:string,
     *   status:string,earliest_submission_on:string,due_on:string,
     *   ruleset_id:string,submission_status:?string
     * }>
     */
    public function submissionDeadlines(
        int $supplierId,
        string $environment,
        string $from,
        string $to,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT obligation.id AS obligation_id,
                    obligation.agenda_code,
                    obligation.subject_type,
                    obligation.subject_reference,
                    obligation.period_start,
                    obligation.period_end,
                    obligation.status,
                    deadline.earliest_submission_on,
                    deadline.due_on,
                    deadline.ruleset_id,
                    (SELECT submission.status
                       FROM payroll_submissions submission
                      WHERE submission.supplier_id = obligation.supplier_id
                        AND submission.environment = obligation.environment
                        AND submission.obligation_id = obligation.id
                      ORDER BY submission.created_at DESC, submission.id DESC
                      LIMIT 1) AS submission_status
               FROM payroll_obligations obligation
               JOIN payroll_submission_deadlines deadline
                 ON deadline.supplier_id = obligation.supplier_id
                AND deadline.environment = obligation.environment
                AND deadline.obligation_id = obligation.id
                AND deadline.deadline_kind = \'regular\'
              WHERE obligation.supplier_id = ?
                AND obligation.environment = ?
                AND obligation.status NOT IN (\'fulfilled\', \'cancelled\')
                AND deadline.due_on >= ?
                AND deadline.due_on <= ?
              ORDER BY deadline.due_on ASC, obligation.id ASC'
        );
        $statement->execute([$supplierId, $environment, $from, $to]);

        return $this->rows($statement);
    }

    /**
     * Nezaplacené odvody s termínem v okně.
     *
     * Bere se jen AKTUÁLNÍ revize běhu: závazky přepočtené revize zůstávají
     * v evidenci kvůli dohledatelnosti, ale platit se má ta poslední. Jinak
     * by přehled hlásil dva termíny na tentýž odvod.
     *
     * @return list<array{
     *   liability_id:int,liability_kind:string,due_on:string,
     *   amount_minor:int,settled_minor:int,recipient_reference:string,
     *   period_start:string,run_id:int
     * }>
     */
    public function levyDeadlines(
        int $supplierId,
        string $from,
        string $to,
    ): array {
        $kinds = implode(
            ',',
            array_fill(0, count(self::LEVY_KINDS), '?'),
        );
        $statement = $this->db->pdo()->prepare(
            'SELECT liability.id AS liability_id,
                    liability.liability_kind,
                    liability.due_on,
                    liability.amount_minor,
                    liability.recipient_reference,
                    run.period_start,
                    run.id AS run_id,
                    COALESCE(settlement.settled_minor, 0) AS settled_minor
               FROM payroll_payment_liabilities liability
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = liability.supplier_id
                AND revision.id = liability.revision_id
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
                AND run.current_revision_no = revision.revision_no
          LEFT JOIN (
                    SELECT supplier_id, liability_id,
                           SUM(amount_minor) AS settled_minor
                      FROM payroll_payment_matches
                     WHERE supplier_id = ? AND liability_id IS NOT NULL
                     GROUP BY supplier_id, liability_id
               ) settlement
                 ON settlement.supplier_id = liability.supplier_id
                AND settlement.liability_id = liability.id
              WHERE liability.supplier_id = ?
                AND liability.direction = \'outgoing\'
                AND liability.liability_kind IN (' . $kinds . ')
                AND liability.due_on >= ?
                AND liability.due_on <= ?
                AND liability.amount_minor
                    > COALESCE(settlement.settled_minor, 0)
              ORDER BY liability.due_on ASC, liability.id ASC'
        );
        $statement->execute([
            $supplierId,
            $supplierId,
            ...self::LEVY_KINDS,
            $from,
            $to,
        ]);

        return $this->rows($statement);
    }

    /**
     * Nevyřízené položky nástupních a výstupních checklistů s termínem v okně.
     *
     * Položka, ke které existuje DOKLAD (evidenční list, potvrzení, evidovaná
     * povinnost podání), se do přehledu nedostane, i když ji nikdo neodklikl —
     * jinak by hlídač připomínal to, co je hotové.
     *
     * @return list<array{
     *   item_id:int,employment_id:int,employee_id:int,full_name:string,
     *   phase:string,item_key:string,due_date:string,
     *   deadline_source:?string,deadline_source_status:?string
     * }>
     */
    public function checklistDeadlines(
        int $supplierId,
        string $from,
        string $to,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT item.id AS item_id,
                    item.employment_id,
                    employment.employee_id,
                    employee.full_name,
                    item.phase,
                    item.item_key,
                    item.due_date,
                    item.deadline_source,
                    item.deadline_source_status
               FROM payroll_employment_checklist_items item
               JOIN payroll_employments employment
                 ON employment.supplier_id = item.supplier_id
                AND employment.id = item.employment_id
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
              WHERE item.supplier_id = ?
                AND item.status = \'pending\'
                AND item.due_date IS NOT NULL
                AND item.due_date >= ?
                AND item.due_date <= ?
                AND employment.status NOT IN (\'no_show\', \'archived\')
                AND NOT EXISTS (
                      SELECT 1 FROM payroll_eldp_statements statement
                       WHERE item.item_key = \'eldp_submission\'
                         AND statement.supplier_id = item.supplier_id
                         AND statement.employment_id = item.employment_id
                    )
                AND NOT EXISTS (
                      SELECT 1 FROM payroll_obligations obligation
                       WHERE item.item_key IN (
                               \'social_jmhz_registration\',
                               \'health_insurance_registration\',
                               \'health_insurance_deregistration\'
                             )
                         AND obligation.supplier_id = item.supplier_id
                         AND obligation.status <> \'cancelled\'
                         AND (
                           (item.item_key = \'social_jmhz_registration\'
                            AND obligation.source_event_type
                                  = \'payroll_employment_registration\'
                            AND obligation.source_event_reference
                                  = CONCAT(\'payroll_employment:\', item.employment_id))
                           OR (item.item_key = \'health_insurance_registration\'
                               AND obligation.source_event_type
                                     = \'payroll_health_notification\'
                               AND obligation.source_event_reference LIKE CONCAT(
                                     \'payroll_health_notification:\',
                                     item.employment_id, \':employment_start:%\'))
                           OR (item.item_key = \'health_insurance_deregistration\'
                               AND obligation.source_event_type
                                     = \'payroll_health_notification\'
                               AND obligation.source_event_reference LIKE CONCAT(
                                     \'payroll_health_notification:\',
                                     item.employment_id, \':employment_end:%\'))
                         )
                    )
              ORDER BY item.due_date ASC, item.id ASC'
        );
        $statement->execute([$supplierId, $from, $to]);

        return $this->rows($statement);
    }

    /**
     * Za která zdaňovací období vůbec vzniká povinnost podat roční vyúčtování.
     *
     * Vyúčtování podává „plátce daně, který ve zdaňovacím období zúčtoval nebo
     * vyplatil příjmy ze závislé činnosti" (§ 38j odst. 4 ZDP) — ne každá firma
     * se zapnutým modulem. Zástupným důkazem je SCHVÁLENÁ revize mzdového běhu
     * v daném roce: přesně z ní vyúčtování čerpá
     * ({@see \MyInvoice\Repository\Payroll\PayrollTaxStatementRepository::monthlyTaxTotals()}),
     * takže firma bez schváleného běhu by dostala termín k tiskopisu, který
     * nejde sestavit.
     *
     * Sražená daň se vrací zvlášť, protože vyúčtování srážkové daně má smysl
     * jen tam, kde plátce v roce opravdu srážel — jinak by hlídač připomínal
     * prázdný tiskopis každé firmě, která zaměstnává jen na HPP.
     *
     * @param list<int> $years
     * @return array<int,array{approved_runs:int,withholding_minor:int}>
     *         klíčem je zdaňovací období
     */
    public function taxStatementBasisYears(int $supplierId, array $years): array
    {
        $years = array_values(array_unique(array_map('intval', $years)));
        if ($years === []) {
            return [];
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT YEAR(run.period_start) AS period_year,
                    COUNT(*) AS approved_runs,
                    COALESCE(SUM(CAST(JSON_VALUE(
                        tax.result_snapshot_json,
                        "$.withholding_tax_minor_units"
                    ) AS SIGNED)), 0) AS withholding_minor
               FROM payroll_runs run
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = run.supplier_id
                AND revision.run_id = run.id
                AND revision.revision_no = run.current_revision_no
                AND revision.status = \'approved\'
          LEFT JOIN payroll_statutory_results tax
                 ON tax.supplier_id = revision.supplier_id
                AND tax.revision_id = revision.id
                AND tax.calculation_kind = \'income_tax\'
                AND tax.result_status = \'calculated\'
              WHERE run.supplier_id = ?
                AND run.period_start >= ?
                AND run.period_start < ?
              GROUP BY YEAR(run.period_start)'
        );
        $statement->execute([
            $supplierId,
            sprintf('%04d-01-01', min($years)),
            sprintf('%04d-01-01', max($years) + 1),
        ]);

        $basis = [];
        foreach ($this->rows($statement) as $row) {
            $year = (int) $row['period_year'];
            if (!in_array($year, $years, true)) {
                continue;
            }
            $basis[$year] = [
                'approved_runs' => (int) $row['approved_runs'],
                'withholding_minor' => (int) $row['withholding_minor'],
            ];
        }

        return $basis;
    }

    /**
     * Prokazatelně PODANÁ roční vyúčtování jako `form_code` → seznam období.
     *
     * Archivace snímku ({@see \MyInvoice\Service\Report\TaxSubmissionArchiver})
     * povinnost nesplní — stažené XML může skončit v koši. Termín proto mizí až
     * ve stavu `submitted`/`accepted`, tedy tam, kde je doložený čas podání
     * a identifikátor podatelny; stejné měřítko používá i řetězec dodatečných
     * přiznání. Na variantě nezáleží: řádné, opravné i dodatečné vyúčtování
     * je podání za tentýž rok.
     *
     * @param list<string> $formCodes
     * @param list<int> $years
     * @return array<string,list<int>>
     */
    public function filedTaxStatementYears(
        int $supplierId,
        array $formCodes,
        array $years,
    ): array {
        $years = array_values(array_unique(array_map('intval', $years)));
        $formCodes = array_values(array_unique(array_map('strval', $formCodes)));
        if ($years === [] || $formCodes === []) {
            return [];
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT form_code, period_year
               FROM tax_submissions
              WHERE supplier_id = ?
                AND form_code IN ('
            . implode(',', array_fill(0, count($formCodes), '?'))
            . ')
                AND period_year IN ('
            . implode(',', array_fill(0, count($years), '?'))
            . ')
                AND period_month IS NULL
                AND period_quarter IS NULL
                AND status IN (\'submitted\', \'accepted\')
              GROUP BY form_code, period_year'
        );
        $statement->execute([$supplierId, ...$formCodes, ...$years]);

        $filed = [];
        foreach ($this->rows($statement) as $row) {
            $filed[(string) $row['form_code']][] = (int) $row['period_year'];
        }

        return $filed;
    }

    /** @return list<array<string,mixed>> */
    private function rows(\PDOStatement $statement): array
    {
        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }
}
