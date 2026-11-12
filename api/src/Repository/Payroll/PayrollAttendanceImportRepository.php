<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PDOException;

/**
 * Dávky importu docházky, jejich řádky hodin a čtení vztahů pro párování osob.
 */
final class PayrollAttendanceImportRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Pracovní vztahy platné aspoň jeden den v období. Legacy projekce se
     * bere jen u osoby, která jiný vztah v období nemá — jinak by táž osoba
     * nabízela dva vztahy a párování by bylo zbytečně nejednoznačné.
     *
     * @return list<array{employment_id:int,employee_id:int,employee_name:string,code:string,relation_type:string,status:string}>
     */
    public function employmentsInPeriod(int $supplierId, string $periodStart, string $periodEnd): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT employment.id, employment.employee_id, employment.code,
                    employment.relation_type, employment.status, employment.is_legacy_projection,
                    ' . PayrollPeopleRepository::fullNameExpression('employee') . ' AS employee_name
               FROM payroll_employments employment
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
              WHERE employment.supplier_id = ?
                AND employment.status IN ("planned", "preregistered", "active", "suspended", "ended")
                AND COALESCE(
                      employment.actual_start_date,
                      employment.start_date,
                      CASE WHEN employment.is_legacy_projection = 1 THEN "1900-01-01" ELSE NULL END
                    ) <= ?
                AND (employment.end_date IS NULL OR employment.end_date >= ?)
              ORDER BY employee_name, employment.is_primary DESC, employment.id'
        );
        $stmt->execute([$supplierId, $periodEnd, $periodStart]);
        $rows = PayrollTimeValue::rows($stmt->fetchAll(PDO::FETCH_ASSOC), 'attendance_employments');
        $hasRegular = [];
        foreach ($rows as $row) {
            if ((int) $row['is_legacy_projection'] === 0) {
                $hasRegular[(int) $row['employee_id']] = true;
            }
        }
        $result = [];
        foreach ($rows as $row) {
            $employeeId = PayrollTimeValue::int($row['employee_id'] ?? null, 'employee_id');
            if ((int) $row['is_legacy_projection'] === 1 && isset($hasRegular[$employeeId])) {
                continue;
            }
            $result[] = [
                'employment_id' => PayrollTimeValue::int($row['id'] ?? null, 'employment_id'),
                'employee_id' => $employeeId,
                'employee_name' => (string) ($row['employee_name'] ?? ''),
                'code' => (string) ($row['code'] ?? ''),
                'relation_type' => (string) ($row['relation_type'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
            ];
        }

        return $result;
    }

    /**
     * Všechna jména, pod kterými osoba v evidenci vystupuje: karta, historická
     * identita i strukturované jméno a příjmení.
     *
     * @param list<int> $employeeIds
     * @return list<array{employee_id:int,name:string}>
     */
    public function personNames(int $supplierId, array $employeeIds): array
    {
        $employeeIds = array_values(array_unique(array_filter($employeeIds, static fn (int $id): bool => $id > 0)));
        if ($employeeIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT employee.id AS employee_id, employee.full_name AS name
               FROM payroll_employees employee
              WHERE employee.supplier_id = ? AND employee.id IN ({$placeholders})
             UNION ALL
             SELECT identity.employee_id, identity.full_name
               FROM payroll_person_identity_history identity
              WHERE identity.supplier_id = ? AND identity.employee_id IN ({$placeholders})
             UNION ALL
             SELECT identity.employee_id, CONCAT_WS(' ', identity.first_name, identity.last_name)
               FROM payroll_person_identity_history identity
              WHERE identity.supplier_id = ? AND identity.employee_id IN ({$placeholders})
                AND identity.first_name IS NOT NULL AND identity.last_name IS NOT NULL"
        );
        $stmt->execute([
            $supplierId, ...$employeeIds,
            $supplierId, ...$employeeIds,
            $supplierId, ...$employeeIds,
        ]);
        $result = [];
        foreach (PayrollTimeValue::rows($stmt->fetchAll(PDO::FETCH_ASSOC), 'attendance_person_names') as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name !== '') {
                $result[] = [
                    'employee_id' => PayrollTimeValue::int($row['employee_id'] ?? null, 'employee_id'),
                    'name' => $name,
                ];
            }
        }

        return $result;
    }

    /** @return list<int> */
    public function employeeIdsByBirthNumberHash(int $supplierId, string $lookupHash): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT employee_id
               FROM payroll_person_identifiers
              WHERE supplier_id = ? AND identifier_type = "birth_number" AND value_hash = ?'
        );
        $stmt->execute([$supplierId, $lookupHash]);

        return array_map(
            static fn (mixed $id): int => PayrollTimeValue::int($id, 'employee_id'),
            $stmt->fetchAll(PDO::FETCH_COLUMN),
        );
    }

    /**
     * Které údaje pro registraci ČSSZ už evidence u vztahů vede: adresa
     * trvalého pobytu a občanství k danému dni, CZ-ISCO, druh činnosti
     * a místo výkonu práce v poslední verzi podmínek, ověřená daňová
     * rezidence, podepsané prohlášení v roce a uložený profil registrace A1.
     * Pár dotazů přes IN, ne dotaz za osobu.
     *
     * @param list<array{employee_id:int,employment_id:int}> $pairs
     * @return array<int,array{residence_address:bool,citizenship:?string,cz_isco_code:?string,activity_code:?string,workplace:bool,tax_residence:bool,tax_declaration:bool,a1_profile:bool,birth_surname:bool,birth_place:bool,birth_country:bool}> podle vztahu
     */
    public function registrationState(int $supplierId, array $pairs, string $onDate, int $year): array
    {
        if ($pairs === []) {
            return [];
        }
        $employees = array_values(array_unique(array_map(static fn (array $pair): int => $pair['employee_id'], $pairs)));
        $employments = array_values(array_unique(array_map(static fn (array $pair): int => $pair['employment_id'], $pairs)));
        $inEmployees = implode(',', array_fill(0, count($employees), '?'));
        $inEmployments = implode(',', array_fill(0, count($employments), '?'));
        $pdo = $this->db->pdo();
        $column = static function (string $sql, array $params) use ($pdo): array {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            return array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
        };

        $addresses = $column(
            "SELECT DISTINCT employee_id FROM payroll_person_addresses
              WHERE supplier_id = ? AND employee_id IN ({$inEmployees}) AND address_type = 'residence'
                AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?)",
            [$supplierId, ...$employees, $onDate, $onDate],
        );
        $stmt = $pdo->prepare(
            "SELECT employee_id, citizenship_country_code, birth_surname, birth_place, birth_country_code FROM (
                SELECT employee_id, citizenship_country_code, birth_surname, birth_place, birth_country_code,
                       ROW_NUMBER() OVER (PARTITION BY employee_id ORDER BY effective_from DESC, id DESC) AS position
                  FROM payroll_person_identity_history
                 WHERE supplier_id = ? AND employee_id IN ({$inEmployees}) AND effective_from <= ?
             ) identity WHERE position = 1"
        );
        $stmt->execute([$supplierId, ...$employees, $onDate]);
        $citizenship = [];
        $birth = [];
        $filled = static fn (mixed $value): bool => is_string($value) && trim($value) !== '';
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $value = $row['citizenship_country_code'];
            $citizenship[(int) $row['employee_id']] = is_string($value) && $value !== '' ? $value : null;
            $birth[(int) $row['employee_id']] = [
                'birth_surname' => $filled($row['birth_surname']),
                'birth_place' => $filled($row['birth_place']),
                'birth_country' => $filled($row['birth_country_code']),
            ];
        }
        $stmt = $pdo->prepare(
            "SELECT employment_id, cz_isco_code, activity_code, work_place, jmhz_workplace_municipality_code FROM (
                SELECT employment_id, cz_isco_code, activity_code, work_place, jmhz_workplace_municipality_code,
                       ROW_NUMBER() OVER (PARTITION BY employment_id ORDER BY effective_from DESC, id DESC) AS position
                  FROM payroll_employment_terms
                 WHERE supplier_id = ? AND employment_id IN ({$inEmployments})
             ) terms WHERE position = 1"
        );
        $stmt->execute([$supplierId, ...$employments]);
        $terms = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $terms[(int) $row['employment_id']] = $row;
        }
        $residences = $column(
            "SELECT DISTINCT employee_id FROM payroll_person_tax_residences
              WHERE supplier_id = ? AND employee_id IN ({$inEmployees}) AND residence <> 'unverified'",
            [$supplierId, ...$employees],
        );
        $declarations = $column(
            "SELECT DISTINCT employee_id FROM payroll_person_tax_declarations
              WHERE supplier_id = ? AND employee_id IN ({$inEmployees}) AND status = 'signed'
                AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?)",
            [$supplierId, ...$employees, sprintf('%04d-12-31', $year), sprintf('%04d-01-01', $year)],
        );
        $profiles = $column(
            "SELECT DISTINCT employment_id FROM payroll_registration_a1_profiles
              WHERE supplier_id = ? AND employment_id IN ({$inEmployments})",
            [$supplierId, ...$employments],
        );

        $result = [];
        foreach ($pairs as $pair) {
            $row = $terms[$pair['employment_id']] ?? [];
            $text = static fn (string $key): ?string => is_string($row[$key] ?? null) && $row[$key] !== '' ? $row[$key] : null;
            $result[$pair['employment_id']] = [
                'residence_address' => isset($addresses[$pair['employee_id']]),
                'citizenship' => $citizenship[$pair['employee_id']] ?? null,
                'cz_isco_code' => $text('cz_isco_code'),
                'activity_code' => $text('activity_code'),
                'workplace' => $text('work_place') !== null || $text('jmhz_workplace_municipality_code') !== null,
                'tax_residence' => isset($residences[$pair['employee_id']]),
                'tax_declaration' => isset($declarations[$pair['employee_id']]),
                'a1_profile' => isset($profiles[$pair['employment_id']]),
                ...($birth[$pair['employee_id']] ?? ['birth_surname' => false, 'birth_place' => false, 'birth_country' => false]),
            ];
        }

        return $result;
    }

    /**
     * Osoby podle otisků rodných čísel a podle kódů vztahů (osobních čísel),
     * bez ohledu na to, jestli mají vztah v období. Dva dotazy přes IN.
     *
     * @param list<string> $hashes
     * @param list<string> $codes
     * @return array{by_hash:array<string,list<int>>,by_code:array<string,list<int>>}
     */
    public function employeesByIdentifiers(int $supplierId, array $hashes, array $codes): array
    {
        $result = ['by_hash' => [], 'by_code' => []];
        $hashes = array_values(array_unique(array_filter($hashes, static fn (string $hash): bool => $hash !== '')));
        if ($hashes !== []) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT employee_id, value_hash FROM payroll_person_identifiers
                  WHERE supplier_id = ? AND identifier_type = "birth_number"
                    AND value_hash IN (' . implode(',', array_fill(0, count($hashes), '?')) . ')'
            );
            $stmt->execute([$supplierId, ...$hashes]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $result['by_hash'][(string) $row['value_hash']][] = (int) $row['employee_id'];
            }
        }
        $codes = array_values(array_unique(array_filter(
            array_map(static fn (string $code): string => mb_strtolower(trim($code), 'UTF-8'), $codes),
            static fn (string $code): bool => $code !== '',
        )));
        if ($codes !== []) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT DISTINCT employee_id, LOWER(code) AS code FROM payroll_employments
                  WHERE supplier_id = ? AND is_legacy_projection = 0
                    AND code IN (' . implode(',', array_fill(0, count($codes), '?')) . ')'
            );
            $stmt->execute([$supplierId, ...$codes]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $result['by_code'][(string) $row['code']][] = (int) $row['employee_id'];
            }
        }

        return $result;
    }

    /**
     * Co o osobách v evidenci ví párování podle rodného čísla: má osoba
     * uložené rodné číslo (jen příznak, hodnota se nečte ani nedešifruje)
     * a jaké má datum narození (aktuální identita, jinak karta).
     *
     * @param list<int> $employeeIds
     * @return array<int,array{has_birth_number:bool,birth_date:?string}>
     */
    public function identityFacts(int $supplierId, array $employeeIds): array
    {
        $employeeIds = array_values(array_unique(array_filter($employeeIds, static fn (int $id): bool => $id > 0)));
        if ($employeeIds === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT employee.id,
                    COALESCE((
                        SELECT identity.birth_date
                          FROM payroll_person_identity_history identity
                         WHERE identity.supplier_id = employee.supplier_id
                           AND identity.employee_id = employee.id
                           AND identity.effective_from <= CURRENT_DATE
                           AND (identity.effective_to IS NULL OR identity.effective_to >= CURRENT_DATE)
                         ORDER BY identity.effective_from DESC, identity.id DESC
                         LIMIT 1
                    ), employee.birth_date) AS birth_date,
                    EXISTS (
                        SELECT 1 FROM payroll_person_identifiers identifier
                         WHERE identifier.supplier_id = employee.supplier_id
                           AND identifier.employee_id = employee.id
                           AND identifier.identifier_type = "birth_number"
                    ) AS has_birth_number
               FROM payroll_employees employee
              WHERE employee.supplier_id = ? AND employee.id IN (' . implode(',', array_fill(0, count($employeeIds), '?')) . ')'
        );
        $stmt->execute([$supplierId, ...$employeeIds]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['id']] = [
                'has_birth_number' => (int) $row['has_birth_number'] === 1,
                'birth_date' => is_string($row['birth_date']) && $row['birth_date'] !== '' ? substr($row['birth_date'], 0, 10) : null,
            ];
        }

        return $result;
    }

    /**
     * Osoby celé evidence s některým z dat narození (karta nebo historie
     * identity) — kandidáti na shodu jména a data narození u podkladů bez
     * rodného čísla.
     *
     * @param list<string> $birthDates Y-m-d
     * @return array<string,list<int>> datum narození → osoby
     */
    public function employeesByBirthDates(int $supplierId, array $birthDates): array
    {
        $birthDates = array_values(array_unique(array_filter(
            $birthDates,
            static fn (string $date): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1,
        )));
        if ($birthDates === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($birthDates), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT id AS employee_id, birth_date FROM payroll_employees
              WHERE supplier_id = ? AND birth_date IN ({$placeholders})
             UNION
             SELECT employee_id, birth_date FROM payroll_person_identity_history
              WHERE supplier_id = ? AND birth_date IN ({$placeholders})"
        );
        $stmt->execute([$supplierId, ...$birthDates, $supplierId, ...$birthDates]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[substr((string) $row['birth_date'], 0, 10)][] = (int) $row['employee_id'];
        }

        return array_map(static fn (array $ids): array => array_values(array_unique($ids)), $result);
    }

    /**
     * Jméno osob a druh jejich posledního vztahu (výchozí druh nové dohody).
     *
     * @param list<int> $employeeIds
     * @return array<int,array{employee_name:string,relation_type:?string}>
     */
    public function employeeSummaries(int $supplierId, array $employeeIds): array
    {
        $employeeIds = array_values(array_unique(array_filter($employeeIds, static fn (int $id): bool => $id > 0)));
        if ($employeeIds === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT employee.id, ' . PayrollPeopleRepository::fullNameExpression('employee') . ' AS employee_name,
                    (SELECT employment.relation_type FROM payroll_employments employment
                      WHERE employment.supplier_id = employee.supplier_id AND employment.employee_id = employee.id
                        AND employment.is_legacy_projection = 0
                      ORDER BY employment.id DESC LIMIT 1) AS relation_type
               FROM payroll_employees employee
              WHERE employee.supplier_id = ? AND employee.id IN (' . implode(',', array_fill(0, count($employeeIds), '?')) . ')'
        );
        $stmt->execute([$supplierId, ...$employeeIds]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['id']] = [
                'employee_name' => (string) $row['employee_name'],
                'relation_type' => is_string($row['relation_type']) ? $row['relation_type'] : null,
            ];
        }

        return $result;
    }

    /**
     * Nástup vztahů (skutečný, jinak plánovaný) jedním dotazem.
     *
     * @param list<int> $employmentIds
     * @return array<int,string>
     */
    public function employmentStarts(int $supplierId, array $employmentIds): array
    {
        $employmentIds = array_values(array_unique(array_filter($employmentIds, static fn (int $id): bool => $id > 0)));
        if ($employmentIds === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, COALESCE(actual_start_date, start_date) AS start_on
               FROM payroll_employments
              WHERE supplier_id = ? AND id IN (' . implode(',', array_fill(0, count($employmentIds), '?')) . ')'
        );
        $stmt->execute([$supplierId, ...$employmentIds]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_string($row['start_on'] ?? null)) {
                $result[(int) $row['id']] = $row['start_on'];
            }
        }

        return $result;
    }

    /** @return array{id:int,row_version:int,status:string}|null */
    public function latestEmploymentOfEmployee(int $supplierId, int $employeeId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, row_version, status
               FROM payroll_employments
              WHERE supplier_id = ? AND employee_id = ?
              ORDER BY id DESC
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $employeeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row = PayrollTimeValue::row($row, 'attendance_employment');

        return [
            'id' => PayrollTimeValue::int($row['id'] ?? null, 'id'),
            'row_version' => PayrollTimeValue::int($row['row_version'] ?? null, 'row_version'),
            'status' => (string) ($row['status'] ?? ''),
        ];
    }

    /**
     * Verze sjednaných podmínek, která platí k začátku období (u nástupu
     * uprostřed měsíce první verze v období), pro každý z vztahů.
     *
     * `monthly_gross_minor` je hodnota, se kterou počítá mzdový běh: verze
     * podmínek, jinak projekce na vztahu. `is_latest` říká, jestli po ní už
     * nenásleduje další verze — opravit jde jen poslední a nová verze musí
     * začínat až po ní.
     *
     * @param list<int> $employmentIds
     * @return array<int,array{terms_id:int,effective_from:string,effective_to:?string,monthly_gross_minor:?int,is_latest:bool,employment_status:string,hourly_in_period:bool}>
     */
    public function termsAt(int $supplierId, array $employmentIds, string $periodStart, string $periodEnd): array
    {
        $employmentIds = array_values(array_unique(array_filter($employmentIds, static fn (int $id): bool => $id > 0)));
        if ($employmentIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($employmentIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "WITH ranked AS (
                SELECT terms.id, terms.employment_id, terms.effective_from, terms.effective_to,
                       terms.monthly_gross_minor, terms.remuneration_type,
                       MAX(CASE WHEN terms.remuneration_type = 'hourly' AND terms.effective_from <= ?
                                 AND (terms.effective_to IS NULL OR terms.effective_to >= ?) THEN 1 ELSE 0 END)
                           OVER (PARTITION BY terms.employment_id) AS hourly_in_period,
                       ROW_NUMBER() OVER (
                           PARTITION BY terms.employment_id
                           ORDER BY terms.effective_from DESC, terms.id DESC
                       ) AS recency
                  FROM payroll_employment_terms terms
                 WHERE terms.supplier_id = ? AND terms.employment_id IN ({$placeholders})
             )
             SELECT ranked.id, ranked.employment_id, ranked.effective_from, ranked.effective_to,
                    CASE WHEN ranked.remuneration_type = 'hourly' THEN NULL
                         ELSE COALESCE(ranked.monthly_gross_minor, employment.monthly_gross_minor) END AS monthly_gross_minor,
                    ranked.recency, ranked.hourly_in_period, employment.status
               FROM ranked
               JOIN payroll_employments employment
                 ON employment.supplier_id = ? AND employment.id = ranked.employment_id
              WHERE ranked.effective_from <= ?
                AND (ranked.effective_to IS NULL OR ranked.effective_to >= ?)
              ORDER BY ranked.employment_id,
                       CASE WHEN ranked.effective_from <= ? THEN 0 ELSE 1 END,
                       CASE WHEN ranked.effective_from <= ? THEN ranked.effective_from END DESC,
                       ranked.effective_from, ranked.id"
        );
        $stmt->execute([$periodEnd, $periodStart, $supplierId, ...$employmentIds, $supplierId, $periodEnd, $periodStart, $periodStart, $periodStart]);
        $result = [];
        foreach (PayrollTimeValue::rows($stmt->fetchAll(PDO::FETCH_ASSOC), 'attendance_terms') as $row) {
            $employmentId = PayrollTimeValue::int($row['employment_id'] ?? null, 'employment_id');
            if (isset($result[$employmentId])) {
                continue;
            }
            $result[$employmentId] = [
                'terms_id' => PayrollTimeValue::int($row['id'] ?? null, 'id'),
                'effective_from' => (string) $row['effective_from'],
                'effective_to' => $row['effective_to'] === null ? null : (string) $row['effective_to'],
                'monthly_gross_minor' => $row['monthly_gross_minor'] === null
                    ? null
                    : PayrollTimeValue::int($row['monthly_gross_minor'], 'monthly_gross_minor'),
                'is_latest' => PayrollTimeValue::int($row['recency'] ?? null, 'recency') === 1,
                'employment_status' => (string) ($row['status'] ?? ''),
                'hourly_in_period' => (int) $row['hourly_in_period'] === 1,
            ];
        }

        return $result;
    }

    /**
     * Vztahy, které už pravděpodobný výdělek mají (v poslední verzi podmínek),
     * nebo mají schválený průměrný výdělek pro čtvrtletí. Těm import
     * pravděpodobný výdělek nenavrhuje. Dva dotazy přes IN.
     *
     * @param list<int> $employmentIds
     * @return array<int,true>
     */
    public function employmentsWithEarningBasis(int $supplierId, array $employmentIds, int $year, int $quarter): array
    {
        $employmentIds = array_values(array_unique(array_filter($employmentIds, static fn (int $id): bool => $id > 0)));
        if ($employmentIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($employmentIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT employment_id FROM (
                SELECT employment_id, probable_hourly_earning_minor,
                       ROW_NUMBER() OVER (PARTITION BY employment_id ORDER BY effective_from DESC, id DESC) AS position
                  FROM payroll_employment_terms
                 WHERE supplier_id = ? AND employment_id IN ({$in})
             ) terms WHERE position = 1 AND probable_hourly_earning_minor IS NOT NULL
             UNION
             SELECT employment_id FROM payroll_average_earning_snapshots
              WHERE supplier_id = ? AND employment_id IN ({$in})
                AND applicable_year = ? AND applicable_quarter = ? AND status = 'approved'"
        );
        $stmt->execute([$supplierId, ...$employmentIds, $supplierId, ...$employmentIds, $year, $quarter]);

        return array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    /**
     * Mzdové běhy, jejichž některá revize počítá s vztahem v měsících od
     * `$from` (do `$to`, je-li zadané). Stornované běhy se nevracejí.
     *
     * @return list<array{run_id:int,period:string,status:string}>
     */
    public function runsCoveringEmployment(int $supplierId, int $employmentId, string $from, ?string $to): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT run.id, run.period_start, run.status
               FROM payroll_run_employments run_employment
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = run_employment.supplier_id
                AND revision.id = run_employment.revision_id
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
              WHERE run_employment.supplier_id = ?
                AND run_employment.employment_id = ?
                AND run.status <> "cancelled"
                AND run.period_start >= ?
                AND (? IS NULL OR run.period_start <= ?)
              ORDER BY run.period_start, run.id'
        );
        $monthStart = substr($from, 0, 8) . '01';
        $stmt->execute([$supplierId, $employmentId, $monthStart, $to, $to]);

        return array_map(static fn (array $row): array => [
            'run_id' => PayrollTimeValue::int($row['id'] ?? null, 'id'),
            'period' => substr((string) $row['period_start'], 0, 7),
            'status' => (string) $row['status'],
        ], PayrollTimeValue::rows($stmt->fetchAll(PDO::FETCH_ASSOC), 'attendance_runs'));
    }

    public function employmentRowVersion(int $supplierId, int $employmentId): ?int
    {
        $stmt = $this->db->pdo()->prepare('SELECT row_version FROM payroll_employments WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $employmentId]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : PayrollTimeValue::int($value, 'row_version');
    }

    /** @return array<string,mixed>|null */
    public function findBatchByHash(int $supplierId, string $periodStart, string $contentSha256): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_attendance_imports
              WHERE supplier_id = ? AND period_start = ? AND content_sha256 = ?'
        );
        $stmt->execute([$supplierId, $periodStart, $contentSha256]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : $this->batch($supplierId, PayrollTimeValue::int($id, 'import_id'));
    }

    /**
     * Založí dávku s řádky. Souběžný druhý požadavek se stejným obsahem
     * narazí na unikátní klíč a vrátí null — volající pak načte dávku, která
     * vyhrála.
     *
     * @param list<array{name:string,sha256:string}> $files
     * @param list<array<string,mixed>> $rules
     * @param list<array{employment_id:int,meaning:string,component_code:string,quantity_millihours:?int,amount_minor:?int,source_ref:string}> $rows
     */
    public function insertBatch(
        int $supplierId,
        string $periodStart,
        string $sourceSystem,
        string $contentSha256,
        array $files,
        array $rules,
        int $personCount,
        array $rows,
        ?int $userId,
    ): ?int {
        $pdo = $this->db->pdo();
        $metricCount = count(array_filter($rows, static fn (array $row): bool => $row['quantity_millihours'] !== null));
        try {
            $pdo->prepare(
                'INSERT INTO payroll_attendance_imports
                    (supplier_id, period_start, source_system, content_sha256, files_json,
                     rules_json, person_count, metric_count, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $supplierId,
                $periodStart,
                $sourceSystem,
                $contentSha256,
                json_encode($files, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode($rules, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $personCount,
                $metricCount,
                $userId,
            ]);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1062 || ($e->errorInfo[1] ?? null) === '1062') {
                return null;
            }
            throw $e;
        }
        $importId = (int) $pdo->lastInsertId();
        $insert = $pdo->prepare(
            'INSERT INTO payroll_attendance_import_rows
                (supplier_id, import_id, employment_id, meaning, component_code,
                 quantity_millihours, amount_minor, source_ref)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($rows as $row) {
            $insert->execute([
                $supplierId,
                $importId,
                $row['employment_id'],
                $row['meaning'],
                $row['component_code'],
                $row['quantity_millihours'],
                $row['amount_minor'],
                mb_substr($row['source_ref'], 0, 191),
            ]);
        }

        return $importId;
    }

    public function attachInputImport(int $supplierId, int $importId, int $inputImportId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_attendance_imports
                SET input_import_id = ?
              WHERE supplier_id = ? AND id = ?'
        )->execute([$inputImportId, $supplierId, $importId]);
    }

    /** @return list<array<string,mixed>> */
    public function batches(int $supplierId, string $sourceSystem, ?string $periodStart, int $limit = 100): array
    {
        $sql = 'SELECT batch.id FROM payroll_attendance_imports batch
                 WHERE batch.supplier_id = ? AND batch.source_system = ?'
            . ($periodStart === null ? '' : ' AND batch.period_start = ?')
            . ' ORDER BY batch.period_start DESC, batch.id DESC LIMIT ' . max(1, min(500, $limit));
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($periodStart === null ? [$supplierId, $sourceSystem] : [$supplierId, $sourceSystem, $periodStart]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $batch = $this->batch($supplierId, PayrollTimeValue::int($id, 'import_id'));
            if ($batch !== null) {
                $result[] = $batch;
            }
        }

        return $result;
    }

    /**
     * Platné mzdové vstupy období, které založil import: dávka docházky
     * (i převod mezd z POHODY / PAMICA, který jde touž cestou) nebo jiný
     * import vstupů. Zrušené vstupy se nepočítají.
     *
     * @return list<array{attendance_import_id:?int,input_import_id:int,files:list<array<string,mixed>>,active_inputs:int,created_at:string}>
     */
    public function activeInputSources(int $supplierId, string $periodStart): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT input_import.id AS input_import_id, input_import.source_name, input_import.created_at,
                    batch.id AS attendance_import_id, batch.files_json, COUNT(pi.id) AS active_inputs
               FROM payroll_inputs pi
               JOIN payroll_input_imports input_import
                 ON input_import.supplier_id = pi.supplier_id AND input_import.id = pi.import_id
               LEFT JOIN payroll_attendance_imports batch
                 ON batch.supplier_id = input_import.supplier_id AND batch.input_import_id = input_import.id
              WHERE pi.supplier_id = ? AND pi.period_start = ? AND pi.status IN ('draft', 'approved', 'locked')
              GROUP BY input_import.id, input_import.source_name, input_import.created_at, batch.id, batch.files_json
              ORDER BY input_import.id, batch.id"
        );
        $stmt->execute([$supplierId, $periodStart]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $raw) {
            $row = PayrollTimeValue::row($raw, 'attendance_input_source');
            $files = $row['files_json'] === null
                ? [['name' => (string) $row['source_name'], 'sha256' => null]]
                : json_decode((string) $row['files_json'], true, 16, JSON_THROW_ON_ERROR);
            $result[] = [
                'attendance_import_id' => $row['attendance_import_id'] === null
                    ? null
                    : PayrollTimeValue::int($row['attendance_import_id'], 'attendance_import_id'),
                'input_import_id' => PayrollTimeValue::int($row['input_import_id'] ?? null, 'input_import_id'),
                'files' => is_array($files) ? array_values($files) : [],
                'active_inputs' => PayrollTimeValue::int($row['active_inputs'] ?? null, 'active_inputs'),
                'created_at' => (string) $row['created_at'],
            ];
        }

        return $result;
    }

    /** @return array<string,mixed>|null */
    public function batch(int $supplierId, int $importId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT batch.id, batch.period_start, batch.source_system, batch.created_at,
                    batch.files_json, batch.person_count, batch.metric_count, batch.input_import_id,
                    creator.name AS created_by_name,
                    COALESCE(input_import.accepted_count, 0) AS input_count
               FROM payroll_attendance_imports batch
               LEFT JOIN users creator ON creator.id = batch.created_by
               LEFT JOIN payroll_input_imports input_import
                 ON input_import.supplier_id = batch.supplier_id
                AND input_import.id = batch.input_import_id
              WHERE batch.supplier_id = ? AND batch.id = ?'
        );
        $stmt->execute([$supplierId, $importId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row = PayrollTimeValue::row($row, 'attendance_import');
        $files = json_decode((string) ($row['files_json'] ?? '[]'), true, 16, JSON_THROW_ON_ERROR);

        return [
            'id' => PayrollTimeValue::int($row['id'] ?? null, 'id'),
            'period' => substr((string) $row['period_start'], 0, 7),
            'source_system' => (string) $row['source_system'],
            'created_at' => (string) $row['created_at'],
            'created_by_name' => $row['created_by_name'] === null ? null : (string) $row['created_by_name'],
            'files' => is_array($files) ? array_values($files) : [],
            'person_count' => PayrollTimeValue::int($row['person_count'] ?? null, 'person_count'),
            'metric_count' => PayrollTimeValue::int($row['metric_count'] ?? null, 'metric_count'),
            'input_count' => PayrollTimeValue::int($row['input_count'] ?? null, 'input_count'),
            'input_import_id' => $row['input_import_id'] === null
                ? null
                : PayrollTimeValue::int($row['input_import_id'], 'input_import_id'),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function batchRows(int $supplierId, int $importId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT row_item.employment_id, row_item.meaning, row_item.component_code,
                    row_item.quantity_millihours, row_item.amount_minor, row_item.source_ref,
                    employment.code AS employment_code,
                    ' . PayrollPeopleRepository::fullNameExpression('employee') . ' AS employee_name
               FROM payroll_attendance_import_rows row_item
               JOIN payroll_employments employment
                 ON employment.supplier_id = row_item.supplier_id
                AND employment.id = row_item.employment_id
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
              WHERE row_item.supplier_id = ? AND row_item.import_id = ?
              ORDER BY employee_name, row_item.employment_id, row_item.id'
        );
        $stmt->execute([$supplierId, $importId]);
        $result = [];
        foreach (PayrollTimeValue::rows($stmt->fetchAll(PDO::FETCH_ASSOC), 'attendance_import_rows') as $row) {
            $result[] = [
                'employment_id' => PayrollTimeValue::int($row['employment_id'] ?? null, 'employment_id'),
                'employee_name' => (string) ($row['employee_name'] ?? ''),
                'employment_code' => (string) ($row['employment_code'] ?? ''),
                'meaning' => (string) $row['meaning'],
                'component_code' => $row['component_code'] === '' ? null : (string) $row['component_code'],
                'quantity_millihours' => $row['quantity_millihours'] === null
                    ? null
                    : PayrollTimeValue::int($row['quantity_millihours'], 'quantity_millihours'),
                'amount_minor' => $row['amount_minor'] === null
                    ? null
                    : PayrollTimeValue::int($row['amount_minor'], 'amount_minor'),
                'source' => (string) $row['source_ref'],
            ];
        }

        return $result;
    }

    /**
     * Výsledky aktuálních revizí mzdových běhů období: hrubá mzda po vztazích
     * a čistá mzda po zaměstnancích. Běh bez vypočtené revize dá prázdné mapy.
     *
     * @return array{run:?array{status:string,revision_status:?string},gross:array<int,int>,net:array<int,int>,employee_of:array<int,int>,employment_count:array<int,int>}
     */
    public function runResultsForPeriod(int $supplierId, string $periodStart): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT run.status AS run_status, revision.id AS revision_id, revision.status AS revision_status
               FROM payroll_runs run
          LEFT JOIN payroll_run_revisions revision
                 ON revision.supplier_id = run.supplier_id
                AND revision.run_id = run.id
                AND revision.revision_no = run.current_revision_no
              WHERE run.supplier_id = ? AND run.period_start = ? AND run.status <> "cancelled"
              ORDER BY run.id'
        );
        $stmt->execute([$supplierId, $periodStart]);
        $run = null;
        $revisionIds = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $calculated = $row['revision_id'] !== null
                && in_array($row['revision_status'], ['calculated', 'reviewed', 'approved'], true);
            if ($run === null || $calculated) {
                $run = [
                    'status' => (string) $row['run_status'],
                    'revision_status' => $row['revision_status'] === null ? null : (string) $row['revision_status'],
                ];
            }
            if ($calculated) {
                $revisionIds[] = (int) $row['revision_id'];
            }
        }
        $results = ['run' => $run, 'gross' => [], 'net' => [], 'employee_of' => [], 'employment_count' => []];
        if ($revisionIds === []) {
            return $results;
        }
        $in = implode(', ', array_fill(0, count($revisionIds), '?'));
        $employments = $this->db->pdo()->prepare(
            "SELECT employment_id, employee_id,
                    JSON_VALUE(result_json, '$.totals.source_amount_minor') AS gross_minor
               FROM payroll_run_employments
              WHERE supplier_id = ? AND revision_id IN ({$in}) AND status = 'calculated'"
        );
        $employments->execute([$supplierId, ...$revisionIds]);
        foreach ($employments->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $employmentId = (int) $row['employment_id'];
            $employeeId = (int) $row['employee_id'];
            $results['gross'][$employmentId] = ($results['gross'][$employmentId] ?? 0) + (int) $row['gross_minor'];
            if (!isset($results['employee_of'][$employmentId])) {
                $results['employee_of'][$employmentId] = $employeeId;
                $results['employment_count'][$employeeId] = ($results['employment_count'][$employeeId] ?? 0) + 1;
            }
        }
        // Čistá mzda (před exekucemi) je ve výsledku osoby z výpočtu zákonných odvodů.
        $net = $this->db->pdo()->prepare(
            "SELECT employee_id,
                    JSON_VALUE(result_json, '$.statutory.net_payable_minor_units') AS net_minor
               FROM payroll_run_persons
              WHERE supplier_id = ? AND revision_id IN ({$in}) AND status = 'calculated'"
        );
        $net->execute([$supplierId, ...$revisionIds]);
        foreach ($net->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['net_minor'] === null) {
                continue;
            }
            $employeeId = (int) $row['employee_id'];
            $results['net'][$employeeId] = ($results['net'][$employeeId] ?? 0) + (int) $row['net_minor'];
        }

        return $results;
    }
}
