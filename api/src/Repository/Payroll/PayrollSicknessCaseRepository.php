<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Evidence případů dávek nemocenského pojištění (NEMPRI, HZUPN).
 *
 * Repozitář vrací HOLÁ FAKTA. Jestli se z případu smí sestavit datová věta
 * a do kdy se má podat, rozhodují {@see \MyInvoice\Service\Payroll\Submission\Sickness\SicknessXmlValidator}
 * a {@see \MyInvoice\Service\Payroll\Submission\Sickness\SicknessDeadlinePolicy}
 * — obojí musí jít otestovat bez databáze.
 */
final readonly class PayrollSicknessCaseRepository
{
    public function __construct(private Connection $db) {}

    /** @return array<string,mixed>|null */
    public function find(
        int $supplierId,
        string $environment,
        int $caseId,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT sickness.*, employee.full_name
               FROM payroll_sickness_cases sickness
               JOIN payroll_employees employee
                 ON employee.supplier_id = sickness.supplier_id
                AND employee.id = sickness.employee_id
              WHERE sickness.supplier_id = ?
                AND sickness.environment = ?
                AND sickness.id = ?'
        );
        $statement->execute([$supplierId, $environment, $caseId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function listForSupplier(
        int $supplierId,
        string $environment,
        ?int $employmentId = null,
    ): array {
        // `probable_income_suggestion_minor` je NÁVRH pravděpodobné výše příjmu:
        // sjednaná měsíční hrubá mzda z podmínek účinných ke dni události.
        // Do věty nejde sám — jde tam jen to, co účetní u případu potvrdí.
        $sql =
            'SELECT sickness.*, employee.full_name,
                    employment.code AS employment_code,
                    employment.start_date AS employment_start_date,
                    employment.actual_start_date AS employment_actual_start_date,
                    employment.end_date AS employment_end_date,
                    employment.relation_type AS employment_relation_type,
                    (SELECT terms.monthly_gross_minor
                       FROM payroll_employment_terms terms
                      WHERE terms.supplier_id = sickness.supplier_id
                        AND terms.employment_id = sickness.employment_id
                        AND terms.effective_from <= sickness.incapacity_from
                        AND (terms.effective_to IS NULL
                             OR terms.effective_to >= sickness.incapacity_from)
                      ORDER BY terms.effective_from DESC, terms.id DESC
                      LIMIT 1) AS probable_income_suggestion_minor
               FROM payroll_sickness_cases sickness
               JOIN payroll_employees employee
                 ON employee.supplier_id = sickness.supplier_id
                AND employee.id = sickness.employee_id
               JOIN payroll_employments employment
                 ON employment.supplier_id = sickness.supplier_id
                AND employment.id = sickness.employment_id
              WHERE sickness.supplier_id = ?
                AND sickness.environment = ?';
        $params = [$supplierId, $environment];
        if ($employmentId !== null) {
            $sql .= ' AND sickness.employment_id = ?';
            $params[] = $employmentId;
        }
        $sql .= ' ORDER BY sickness.incapacity_from DESC, sickness.id DESC';
        $statement = $this->db->pdo()->prepare($sql);
        $statement->execute($params);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Fakta pracovního vztahu, ze kterých se datová věta sestaví.
     *
     * `activity_code` se čte z podmínek účinných ke dni vzniku sociální
     * události, ne z posledních platných: `zamestnani/druhCinnosti` má
     * vypovídat o vztahu v době, kdy událost nastala.
     *
     * @return array<string,mixed>|null
     */
    public function findEmploymentContext(
        int $supplierId,
        int $employmentId,
        string $onDate,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT employment.id AS employment_id,
                    employment.employee_id,
                    employment.relation_type,
                    employment.start_date,
                    employment.actual_start_date,
                    employment.end_date,
                    employment.status,
                    employee.full_name,
                    terms.activity_code,
                    supplier.company_name AS employer_name,
                    supplier.ic AS employer_business_id,'
                    . PayrollEmployerIdentifierSql::SELECT . '
               FROM payroll_employments employment
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
               JOIN supplier
                 ON supplier.id = employment.supplier_id'
                . PayrollEmployerIdentifierSql::JOINS . '
          LEFT JOIN payroll_employment_terms terms
                 ON terms.supplier_id = employment.supplier_id
                AND terms.employment_id = employment.id
                AND terms.effective_from <= ?
                AND (terms.effective_to IS NULL OR terms.effective_to >= ?)
              WHERE employment.supplier_id = ?
                AND employment.id = ?
              ORDER BY terms.effective_from DESC, terms.id DESC
              LIMIT 1'
        );
        $statement->execute([$onDate, $onDate, $supplierId, $employmentId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * Případy téhož vztahu, které se s obdobím překrývají. Dvě neschopnosti
     * téhož druhu ve stejný den by znamenaly dvě podání za tutéž věc.
     *
     * @return list<array<string,mixed>>
     */
    public function overlappingForEmployment(
        int $supplierId,
        string $environment,
        int $employmentId,
        string $benefitKind,
        string $incapacityFrom,
        ?string $incapacityTo,
        ?int $excludeCaseId = null,
    ): array {
        $sql =
            'SELECT id, benefit_kind, incapacity_from, incapacity_to, status
               FROM payroll_sickness_cases
              WHERE supplier_id = ?
                AND environment = ?
                AND employment_id = ?
                AND benefit_kind = ?
                AND cancelled = 0
                AND (incapacity_to IS NULL OR incapacity_to >= ?)
                AND (? IS NULL OR incapacity_from <= ?)';
        $params = [
            $supplierId,
            $environment,
            $employmentId,
            $benefitKind,
            $incapacityFrom,
            $incapacityTo,
            $incapacityTo,
        ];
        if ($excludeCaseId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeCaseId;
        }
        $sql .= ' ORDER BY incapacity_from, id';
        $statement = $this->db->pdo()->prepare($sql);
        $statement->execute($params);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Případy, u kterých aspoň jedno podání ještě čeká — podklad hlídače termínů.
     *
     * Vrací i případy, ze kterých ještě nikdo podání nepřipravil. To je celý
     * smysl: lhůta podle § 97 odst. 2 běží od 15. dne neschopnosti bez ohledu
     * na to, jestli si toho někdo všiml.
     *
     * Rozhoduje se PO PODÁNÍCH: přijaté NEMPRI případ neuzavře, dokud čeká
     * HZUPN (§ 97 odst. 3). HZUPN existuje jen u nemocenského.
     *
     * @return list<array<string,mixed>>
     */
    public function openCases(
        int $supplierId,
        string $environment,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT sickness.id AS case_id,
                    sickness.employee_id,
                    sickness.employment_id,
                    sickness.benefit_kind,
                    sickness.incapacity_from,
                    sickness.incapacity_to,
                    sickness.status,
                    sickness.nempri_status,
                    sickness.hzupn_status,
                    sickness.nempri_submission_id,
                    sickness.hzupn_submission_id,
                    sickness.nempri_transfer_status,
                    sickness.nempri_transfer_submission_id,
                    sickness.transferred_other_work,
                    sickness.transferred_on,
                    sickness.transfer_reason,
                    sickness.action_start,
                    sickness.returned_on,
                    sickness.lone_caregiver,
                    sickness.payroll_payment_date,
                    sickness.worked_on_decisive_day,
                    sickness.hours_worked,
                    sickness.daily_working_hours,
                    sickness.small_scope_income_minor,
                    employee.full_name,
                    employment.end_date AS employment_end_date,
                    employment.relation_type AS employment_relation_type
               FROM payroll_sickness_cases sickness
               JOIN payroll_employees employee
                 ON employee.supplier_id = sickness.supplier_id
                AND employee.id = sickness.employee_id
          LEFT JOIN payroll_employments employment
                 ON employment.supplier_id = sickness.supplier_id
                AND employment.id = sickness.employment_id
              WHERE sickness.supplier_id = ?
                AND sickness.environment = ?
                AND sickness.cancelled = 0
                AND (sickness.nempri_status IN ("pending", "rejected")
                     OR sickness.nempri_transfer_status IN ("pending", "rejected")
                     OR (sickness.benefit_kind = "NEM"
                         AND sickness.hzupn_status IN ("pending", "rejected")))
              ORDER BY sickness.incapacity_from, sickness.id'
        );
        $statement->execute([$supplierId, $environment]);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Případ podle jeho přirozeného klíče (vztah, druh dávky, den vzniku).
     * Tentýž klíč drží unikátní index, takže převod spuštěný podruhé případ
     * nezdvojí.
     *
     * @return array<string,mixed>|null
     */
    public function findByScope(
        int $supplierId,
        string $environment,
        int $employmentId,
        string $benefitKind,
        string $incapacityFrom,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT *
               FROM payroll_sickness_cases
              WHERE supplier_id = ?
                AND environment = ?
                AND employment_id = ?
                AND benefit_kind = ?
                AND incapacity_from = ?
              LIMIT 1'
        );
        $statement->execute([$supplierId, $environment, $employmentId, $benefitKind, $incapacityFrom]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * Nezrušené případy vztahu a druhu dávky s daným číslem rozhodnutí (číslo
     * eNeschopenky). Podle něj ČSSZ páruje NEMPRI i HZUPN téže události, takže
     * podání cizího programu se k případu přiřadí bez ohledu na dny.
     *
     * @return list<array<string,mixed>>
     */
    public function findByDecisionNumber(
        int $supplierId,
        string $environment,
        int $employmentId,
        string $benefitKind,
        string $decisionNumber,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT *
               FROM payroll_sickness_cases
              WHERE supplier_id = ?
                AND environment = ?
                AND employment_id = ?
                AND benefit_kind = ?
                AND decision_number = ?
                AND cancelled = 0
              ORDER BY incapacity_from, id'
        );
        $statement->execute([$supplierId, $environment, $employmentId, $benefitKind, $decisionNumber]);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Nezrušený případ, jehož konec tvoří zadaná nepřítomnost: končí týmž dnem
     * a začal dřív. Navazující nepřítomnost případ jen prodloužila, takže vazbu
     * `absence_id` nemá; po jejím zrušení se konec případu musí vrátit zpět.
     *
     * @return array<string,mixed>|null
     */
    public function caseEndingWith(
        int $supplierId,
        string $environment,
        int $employmentId,
        string $benefitKind,
        string $absenceFrom,
        string $absenceTo,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT *
               FROM payroll_sickness_cases
              WHERE supplier_id = ?
                AND environment = ?
                AND employment_id = ?
                AND benefit_kind = ?
                AND cancelled = 0
                AND incapacity_to = ?
                AND incapacity_from < ?
              ORDER BY incapacity_from DESC, id DESC
              LIMIT 1'
        );
        $statement->execute([$supplierId, $environment, $employmentId, $benefitKind, $absenceTo, $absenceFrom]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * Délka zveřejněné směny v den vzniku události, v minutách bez přestávky.
     * Den se určuje v časovém pásmu směny, stejně jako u výpočtu náhrady mzdy.
     * `null` = ten den žádnou zveřejněnou směnu nemá.
     */
    public function publishedShiftMinutesOn(
        int $supplierId,
        int $employmentId,
        string $date,
    ): ?int {
        if (!$this->db->hasTable('payroll_shifts')) {
            return null;
        }
        $day = new \DateTimeImmutable($date . ' 00:00:00', new \DateTimeZone('UTC'));
        $statement = $this->db->pdo()->prepare(
            "SELECT starts_at_utc, ends_at_utc, timezone_name, break_minutes
               FROM payroll_shifts
              WHERE supplier_id = ?
                AND employment_id = ?
                AND status = 'published'
                AND starts_at_utc < ?
                AND ends_at_utc > ?
              ORDER BY starts_at_utc, id"
        );
        $statement->execute([
            $supplierId,
            $employmentId,
            $day->modify('+2 days')->format('Y-m-d H:i:s'),
            $day->modify('-1 day')->format('Y-m-d H:i:s'),
        ]);
        $minutes = null;
        $utc = new \DateTimeZone('UTC');
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $shift) {
            $start = new \DateTimeImmutable((string) $shift['starts_at_utc'], $utc);
            $end = new \DateTimeImmutable((string) $shift['ends_at_utc'], $utc);
            $local = $start->setTimezone(new \DateTimeZone((string) $shift['timezone_name']))->format('Y-m-d');
            if ($local !== $date) {
                continue;
            }
            $length = intdiv($end->getTimestamp() - $start->getTimestamp(), 60) - (int) $shift['break_minutes'];
            if ($length > 0) {
                $minutes = ($minutes ?? 0) + $length;
            }
        }

        return $minutes;
    }

    /**
     * Schválené absence druhu, ze kterého plyne dávka, ke kterým případ
     * nevznikl — typicky proto, že firmě při schválení chyběl kód OSSZ.
     * Absence, kterou kryje existující případ (navazující část řetězu),
     * ani DPN schválená bez nároku sem nepatří.
     *
     * @param list<string> $absenceTypes
     * @return list<array<string,mixed>>
     */
    public function approvedAbsencesWithoutCase(
        int $supplierId,
        string $environment,
        array $absenceTypes,
        string $endingFrom,
    ): array {
        if ($absenceTypes === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($absenceTypes), '?'));
        $statement = $this->db->pdo()->prepare(
            "SELECT absence.*
               FROM payroll_absences absence
              WHERE absence.supplier_id = ?
                AND absence.status = 'approved'
                AND absence.absence_type IN ({$placeholders})
                AND absence.date_to >= ?
                AND NOT EXISTS (
                    SELECT 1 FROM payroll_sickness_cases sickness_case
                     WHERE sickness_case.supplier_id = absence.supplier_id
                       AND sickness_case.environment = ?
                       AND (sickness_case.absence_id = absence.id
                            OR (sickness_case.employment_id = absence.employment_id
                                AND sickness_case.cancelled = 0
                                AND sickness_case.incapacity_from <= absence.date_to
                                AND (sickness_case.incapacity_to IS NULL
                                     OR sickness_case.incapacity_to >= absence.date_from)))
                )
                AND NOT EXISTS (
                    SELECT 1 FROM payroll_sickness_events sickness_event
                     WHERE sickness_event.supplier_id = absence.supplier_id
                       AND sickness_event.absence_id = absence.id
                       AND sickness_event.insurance_eligibility_confirmed = 0
                )
              ORDER BY absence.employment_id, absence.date_from, absence.id"
        );
        $statement->execute([$supplierId, ...$absenceTypes, $endingFrom, $environment]);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Případ, který vznikl z dané absence.
     *
     * @return array<string,mixed>|null
     */
    public function findByAbsence(
        int $supplierId,
        string $environment,
        int $absenceId,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT *
               FROM payroll_sickness_cases
              WHERE supplier_id = ?
                AND environment = ?
                AND absence_id = ?
              ORDER BY id
              LIMIT 1'
        );
        $statement->execute([$supplierId, $environment, $absenceId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * Otevřený případ téhož druhu, který končí den před `$nextDay`. Neschopnost
     * zapsaná po měsících je JEDNA sociální událost; navazující absence proto
     * prodlužuje tentýž případ, dokud z něj nikdo nepodal hlášení o skončení.
     *
     * U nemocenského rozhoduje jen HZUPN: přijaté NEMPRI událost neukončuje,
     * takže prodloužení po něm patří do téhož případu (jinak by se jedna
     * neschopnost rozpadla na dva případy). U ostatních dávek se navazuje,
     * dokud NEMPRI není vyřízené.
     *
     * @return array<string,mixed>|null
     */
    public function contiguousOpenCase(
        int $supplierId,
        string $environment,
        int $employmentId,
        string $benefitKind,
        string $nextDay,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT *
               FROM payroll_sickness_cases
              WHERE supplier_id = ?
                AND environment = ?
                AND employment_id = ?
                AND benefit_kind = ?
                AND cancelled = 0
                AND hzupn_submission_id IS NULL
                AND ((benefit_kind = "NEM" AND hzupn_status IN ("pending", "rejected"))
                     OR (benefit_kind <> "NEM" AND nempri_status IN ("pending", "rejected")))
                AND incapacity_to = DATE_SUB(?, INTERVAL 1 DAY)
              ORDER BY incapacity_from DESC, id DESC
              LIMIT 1'
        );
        $statement->execute([$supplierId, $environment, $employmentId, $benefitKind, $nextDay]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $data */
    public function insert(
        int $supplierId,
        string $environment,
        array $data,
    ): int {
        $columns = array_keys($data);
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_sickness_cases
                 (supplier_id, environment, ' . implode(', ', $columns) . ')
             VALUES (?, ?, ' . implode(', ', array_fill(0, count($columns), '?')) . ')'
        );
        $statement->execute([
            $supplierId,
            $environment,
            ...array_values($data),
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Optimistický zápis. Bez `row_version` v podmínce by dva souběžné
     * požadavky mohly z odmítnutého případu udělat přijatý.
     *
     * @param array<string,mixed> $changes
     */
    public function update(
        int $supplierId,
        string $environment,
        int $caseId,
        int $rowVersion,
        array $changes,
    ): bool {
        $assignments = ['row_version = row_version + 1'];
        $params = [];
        foreach ($changes as $column => $value) {
            $assignments[] = $column . ' = ?';
            $params[] = $value;
        }
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_sickness_cases
                SET ' . implode(', ', $assignments) . '
              WHERE supplier_id = ?
                AND environment = ?
                AND id = ?
                AND row_version = ?'
        );
        $statement->execute([
            ...$params,
            $supplierId,
            $environment,
            $caseId,
            $rowVersion,
        ]);

        return $statement->rowCount() === 1;
    }

    /** @return list<array{from:string,to:string}> */
    public function workDays(
        int $supplierId,
        string $environment,
        int $caseId,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT worked_from, worked_to
               FROM payroll_sickness_case_work_days
              WHERE supplier_id = ?
                AND environment = ?
                AND case_id = ?
              ORDER BY worked_from'
        );
        $statement->execute([$supplierId, $environment, $caseId]);
        $intervals = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $intervals[] = [
                'from' => (string) $row['worked_from'],
                'to' => (string) $row['worked_to'],
            ];
        }

        return $intervals;
    }

    /**
     * Přepíše dny práce v době neschopnosti.
     *
     * Přepis, ne přírůstek: hlášení nese ÚPLNÝ seznam intervalů a doplňovat
     * je po jednom by znamenalo, že smazaný interval v hlášení zůstane.
     *
     * @param list<array{from:string,to:string}> $intervals
     */
    public function replaceWorkDays(
        int $supplierId,
        string $environment,
        int $caseId,
        array $intervals,
    ): void {
        $delete = $this->db->pdo()->prepare(
            'DELETE FROM payroll_sickness_case_work_days
              WHERE supplier_id = ?
                AND environment = ?
                AND case_id = ?'
        );
        $delete->execute([$supplierId, $environment, $caseId]);
        if ($intervals === []) {
            return;
        }
        $insert = $this->db->pdo()->prepare(
            'INSERT INTO payroll_sickness_case_work_days
                 (supplier_id, environment, case_id, worked_from, worked_to)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($intervals as $interval) {
            $insert->execute([
                $supplierId,
                $environment,
                $caseId,
                $interval['from'],
                $interval['to'],
            ]);
        }
    }

    /**
     * Ručně doplněné měsíce rozhodného období, klíčované `YYYY-MM`.
     *
     * @return array<string,array{income_minor:int,excluded_days:int}>
     */
    public function decisiveMonths(
        int $supplierId,
        string $environment,
        int $caseId,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT period_year, period_month, countable_income_minor, excluded_days
               FROM payroll_sickness_case_decisive_months
              WHERE supplier_id = ?
                AND environment = ?
                AND case_id = ?
              ORDER BY period_year, period_month'
        );
        $statement->execute([$supplierId, $environment, $caseId]);
        $months = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $months[sprintf('%04d-%02d', (int) $row['period_year'], (int) $row['period_month'])] = [
                'income_minor' => (int) $row['countable_income_minor'],
                'excluded_days' => (int) $row['excluded_days'],
            ];
        }

        return $months;
    }

    /**
     * Přepíše ruční měsíce rozhodného období. Přepis ze stejného důvodu jako
     * u dnů práce: smazaný měsíc nesmí ve větě zůstat.
     *
     * @param array<string,array{income_minor:int,excluded_days:int}> $months
     */
    public function replaceDecisiveMonths(
        int $supplierId,
        string $environment,
        int $caseId,
        array $months,
    ): void {
        $this->db->pdo()->prepare(
            'DELETE FROM payroll_sickness_case_decisive_months
              WHERE supplier_id = ?
                AND environment = ?
                AND case_id = ?'
        )->execute([$supplierId, $environment, $caseId]);
        if ($months === []) {
            return;
        }
        $insert = $this->db->pdo()->prepare(
            'INSERT INTO payroll_sickness_case_decisive_months
                 (supplier_id, environment, case_id, period_year, period_month,
                  countable_income_minor, excluded_days)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($months as $period => $month) {
            [$year, $number] = array_map('intval', explode('-', $period));
            $insert->execute([
                $supplierId,
                $environment,
                $caseId,
                $year,
                $number,
                $month['income_minor'],
                $month['excluded_days'],
            ]);
        }
    }

    /**
     * Vyživovaná osoba zaměstnance, kterou případ uvádí jako dítě nebo
     * ošetřovanou osobu. Rodné číslo zůstává zašifrované; odhalí ho až služba,
     * která sestavuje větu.
     *
     * @return array<string,mixed>|null
     */
    public function dependant(
        int $supplierId,
        int $employeeId,
        int $dependantId,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, full_name, given_name, family_name, birth_date,
                    birth_number_ciphertext
               FROM payroll_dependants
              WHERE supplier_id = ?
                AND employee_id = ?
                AND id = ?'
        );
        $statement->execute([$supplierId, $employeeId, $dependantId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * Výplatní profil osoby: způsob výplaty mzdy, účet, na který mzda chodí
     * ke dni události, a adresa bydliště.
     *
     * Účet se vybírá stejně jako pro výplatu: aktivní, účinný k datu, s největším
     * podílem; při shodě ten s pozdějším začátkem účinnosti.
     *
     * @return array{
     *   payout_method:?string,
     *   account:?array{id:int,ciphertext:string,hash:string},
     *   address:?array{street_line:string,city:string,postal_code:string,country_code:string}
     * }
     */
    public function payoutTarget(
        int $supplierId,
        int $employeeId,
        string $onDate,
    ): array {
        $pdo = $this->db->pdo();
        $profile = $pdo->prepare(
            'SELECT payout_method FROM payroll_employee_profiles
              WHERE supplier_id = ? AND employee_id = ?'
        );
        $profile->execute([$supplierId, $employeeId]);
        $method = $profile->fetchColumn();

        $account = $pdo->prepare(
            'SELECT id, bank_account_ciphertext, HEX(bank_account_hash) AS bank_account_hash
               FROM payroll_person_accounts
              WHERE supplier_id = ?
                AND employee_id = ?
                AND is_active = 1
                AND effective_from <= ?
                AND (effective_to IS NULL OR effective_to >= ?)
              ORDER BY allocation_basis_points DESC, effective_from DESC, id DESC
              LIMIT 1'
        );
        $account->execute([$supplierId, $employeeId, $onDate, $onDate]);
        $accountRow = $account->fetch(PDO::FETCH_ASSOC);

        $address = $pdo->prepare(
            'SELECT street_line, city, postal_code, country_code
               FROM payroll_person_addresses
              WHERE supplier_id = ?
                AND employee_id = ?
                AND address_type = "residence"
                AND effective_from <= ?
                AND (effective_to IS NULL OR effective_to >= ?)
              ORDER BY effective_from DESC, id DESC
              LIMIT 1'
        );
        $address->execute([$supplierId, $employeeId, $onDate, $onDate]);
        $addressRow = $address->fetch(PDO::FETCH_ASSOC);

        return [
            'payout_method' => is_string($method) ? $method : null,
            'account' => is_array($accountRow)
                ? [
                    'id' => (int) $accountRow['id'],
                    'ciphertext' => (string) $accountRow['bank_account_ciphertext'],
                    'hash' => strtolower((string) $accountRow['bank_account_hash']),
                ]
                : null,
            'address' => is_array($addressRow)
                ? [
                    'street_line' => (string) $addressRow['street_line'],
                    'city' => (string) $addressRow['city'],
                    'postal_code' => (string) $addressRow['postal_code'],
                    'country_code' => (string) $addressRow['country_code'],
                ]
                : null,
        ];
    }

    /**
     * Transakce, která se umí vnořit do už běžící (savepointem).
     *
     * `Connection` žádnou metodu `transaction()` nemá; dřívější volání
     * `$this->db->transaction()` proto shodilo každé založení případu
     * i každou úpravu dnů práce. Stejný vzor jako `PayrollSubmissionRepository`.
     */
    public function transaction(callable $work): mixed
    {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        $savepoint = null;
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        } else {
            $savepoint = 'payroll_sickness_' . bin2hex(random_bytes(6));
            $pdo->exec('SAVEPOINT ' . $savepoint);
        }

        try {
            $result = $work();
            if ($ownsTransaction) {
                $pdo->commit();
            } elseif ($savepoint !== null) {
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }

            return $result;
        } catch (\Throwable $exception) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            } elseif ($savepoint !== null) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            throw $exception;
        }
    }
}
