<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Výsledky mzdového běhu PO OSOBÁCH: stránka přehledu a detail jedné osoby.
 *
 * Detail běhu dřív posílal výsledky všech osob najednou (u 150 lidí 2,8 MB)
 * a obrazovka z nich ukázala jednu. Přehled proto čte částky ze sloupců
 * `payroll_run_persons` (plní je {@see PayrollRunRepository::saveCalculation()},
 * starým řádkům je doplnila migrace 2285) a stránkuje, řadí i hledá v SQL.
 * Celý JSON výsledek osoby se čte jen v detailu jedné osoby.
 *
 * Všechny dotazy jdou po `uq_payroll_run_person (supplier_id, revision_id,
 * employee_id)`, tedy nad osobami JEDNÉ revize. Řazení podle částky i hledání
 * podle jména se tak týká nejvýš tolika řádků, kolik má firma lidí v měsíci,
 * a s historií firmy neroste.
 */
final class PayrollRunPeopleRepository
{
    public const MAX_LIMIT = 100;

    public const DEFAULT_LIMIT = 25;

    public const DEFAULT_SORT = 'name';

    /**
     * Řazení přehledu. Klíč jde z URL, výraz jen odsud. Pojistné a daň jsou
     * součty dvou sloupců; osoba bez zákonného výsledku je nemá (`NULL`)
     * a řadí se v obou směrech na konec.
     */
    private const AMOUNT_SORTS = [
        'gross_minor' => 'person.gross_minor',
        'insurance_minor' => '(person.employee_social_minor + person.employee_health_minor)',
        'tax_minor' => '(person.advance_tax_minor + person.withholding_tax_minor)',
        'net_minor' => 'person.net_minor',
        'payable_minor' => 'person.payable_minor',
    ];

    public const SORT_KEYS = [
        'name',
        'gross_minor',
        'insurance_minor',
        'tax_minor',
        'net_minor',
        'payable_minor',
    ];

    /** Stejný escapovací znak jako v seznamu osob ({@see PayrollPeopleRepository}). */
    private const LIKE_ESCAPE = '!';

    public function __construct(private readonly Connection $db) {}

    /**
     * Hlavička běhu a jeho aktuální revize, bez snímků.
     *
     * @return array{
     *   run_id:int,period_start:string,run_status:string,revision_id:?int,
     *   revision_no:?int,revision_status:?string,has_result:bool
     * }|null
     */
    public function currentRevision(int $supplierId, int $runId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT run.id AS run_id,
                    run.period_start,
                    run.status AS run_status,
                    revision.id AS revision_id,
                    revision.revision_no,
                    revision.status AS revision_status,
                    (revision.result_snapshot_hash IS NOT NULL) AS has_result
               FROM payroll_runs run
          LEFT JOIN payroll_run_revisions revision
                 ON revision.supplier_id = run.supplier_id
                AND revision.run_id = run.id
                AND revision.revision_no = run.current_revision_no
              WHERE run.supplier_id = ? AND run.id = ?',
        );
        $stmt->execute([$supplierId, $runId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'run_id' => (int) $row['run_id'],
            'period_start' => (string) $row['period_start'],
            'run_status' => (string) $row['run_status'],
            'revision_id' => $row['revision_id'] === null ? null : (int) $row['revision_id'],
            'revision_no' => $row['revision_no'] === null ? null : (int) $row['revision_no'],
            'revision_status' => $row['revision_status'],
            'has_result' => (bool) (int) ($row['has_result'] ?? 0),
        ];
    }

    /**
     * Stránka osob revize se součty za celý zúžený výběr.
     *
     * `$flaggedEmployeeIds` zužuje na osoby s nálezem: `null` = nezužovat,
     * jinak osoby se zákonným výsledkem k ručnímu posouzení nebo s chybou
     * a k nim osoby z předaného seznamu (nálezy kontrol běhu, které určuje
     * volající, protože zná trvale skrytá varování).
     *
     * @param list<int>|null $flaggedEmployeeIds
     * @return array{
     *   items:list<array<string,mixed>>,
     *   total:int,
     *   totals:array<string,int>
     * }
     */
    public function page(
        int $supplierId,
        int $revisionId,
        int $limit = self::DEFAULT_LIMIT,
        int $offset = 0,
        string $sort = self::DEFAULT_SORT,
        bool $descending = false,
        string $search = '',
        ?array $flaggedEmployeeIds = null,
    ): array {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $offset = max(0, $offset);
        $name = PayrollPeopleRepository::fullNameExpression('employee');

        $where = ' WHERE person.supplier_id = ? AND person.revision_id = ?
                     AND person.result_json IS NOT NULL';
        $params = [$supplierId, $revisionId];
        $search = trim($search);
        if ($search !== '') {
            // Kód je osobní číslo pracovního vztahu; hledá se jen mezi vztahy,
            // se kterými osoba do této revize vstoupila.
            $where .= ' AND (' . $name . " LIKE ? ESCAPE '" . self::LIKE_ESCAPE . "'"
                . ' OR EXISTS (
                        SELECT 1
                          FROM payroll_run_employments search_run
                          JOIN payroll_employments search_employment
                            ON search_employment.supplier_id = search_run.supplier_id
                           AND search_employment.id = search_run.employment_id
                         WHERE search_run.supplier_id = person.supplier_id
                           AND search_run.revision_id = person.revision_id
                           AND search_run.employee_id = person.employee_id
                           AND search_employment.code LIKE ? ESCAPE \'' . self::LIKE_ESCAPE . '\'))';
            $like = '%' . self::escapeLike($search) . '%';
            array_push($params, $like, $like);
        }
        if ($flaggedEmployeeIds !== null) {
            $flagged = 'person.result_status IN ("manual_review", "error")';
            if ($flaggedEmployeeIds !== []) {
                $flagged .= ' OR person.employee_id IN ('
                    . implode(', ', array_fill(0, count($flaggedEmployeeIds), '?')) . ')';
                array_push($params, ...$flaggedEmployeeIds);
            }
            $where .= ' AND (' . $flagged . ')';
        }
        $from = ' FROM payroll_run_persons person
                  JOIN payroll_employees employee
                    ON employee.supplier_id = person.supplier_id
                   AND employee.id = person.employee_id';

        $totalsStmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) AS total,
                    COALESCE(SUM(person.gross_minor), 0) AS gross_minor,
                    COALESCE(SUM(person.employee_social_minor), 0)
                        + COALESCE(SUM(person.employee_health_minor), 0) AS insurance_minor,
                    COALESCE(SUM(person.advance_tax_minor), 0)
                        + COALESCE(SUM(person.withholding_tax_minor), 0) AS tax_minor,
                    COALESCE(SUM(person.tax_bonus_minor), 0) AS tax_bonus_minor,
                    COALESCE(SUM(person.net_minor), 0) AS net_minor,
                    COALESCE(SUM(person.payable_minor), 0) AS payable_minor'
            . $from . $where,
        );
        $totalsStmt->execute($params);
        $totalsRow = $totalsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $totals = [];
        foreach (['gross_minor', 'insurance_minor', 'tax_minor', 'tax_bonus_minor', 'net_minor', 'payable_minor'] as $key) {
            $totals[$key] = (int) ($totalsRow[$key] ?? 0);
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT person.employee_id,
                    ' . $name . ' AS full_name,
                    person.result_status,
                    person.gross_minor,
                    person.employee_social_minor,
                    person.employee_health_minor,
                    person.advance_tax_minor,
                    person.withholding_tax_minor,
                    person.tax_bonus_minor,
                    person.net_minor,
                    person.payable_minor'
            . $from . $where . self::orderBy($sort, $descending)
            . ' LIMIT ? OFFSET ?',
        );
        $position = 1;
        foreach ($params as $param) {
            $stmt->bindValue($position++, $param, is_int($param) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue($position++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($position, $offset, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = self::castItem($row);
        }

        return [
            'items' => $items,
            'total' => (int) ($totalsRow['total'] ?? 0),
            'totals' => $totals,
        ];
    }

    /**
     * Pracovní vztahy, se kterými osoby do revize vstoupily, pro popisek
     * řádku (osobní číslo a druh vztahu). Jeden dotaz na celou stránku.
     *
     * @param list<int> $employeeIds
     * @return array<int,list<array{id:int,code:string,relation_type:string}>>
     */
    public function employments(int $supplierId, int $revisionId, array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT run_employment.employee_id,
                    employment.id,
                    employment.code,
                    employment.relation_type
               FROM payroll_run_employments run_employment
               JOIN payroll_employments employment
                 ON employment.supplier_id = run_employment.supplier_id
                AND employment.id = run_employment.employment_id
              WHERE run_employment.supplier_id = ?
                AND run_employment.revision_id = ?
                AND run_employment.employee_id IN ('
            . implode(', ', array_fill(0, count($employeeIds), '?')) . ')
              ORDER BY employment.is_primary DESC, employment.id',
        );
        $stmt->execute([$supplierId, $revisionId, ...$employeeIds]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['employee_id']][] = [
                'id' => (int) $row['id'],
                'code' => (string) $row['code'],
                'relation_type' => (string) $row['relation_type'],
            ];
        }

        return $result;
    }

    /**
     * Ke kterému zaměstnanci patří který vztah revize. Čte jen identifikátory
     * z unikátního indexu vlastníka, ne JSON vstupů vztahu.
     *
     * @return array<int,int> employment_id => employee_id
     */
    public function employmentOwners(int $supplierId, int $revisionId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT employment_id, employee_id
               FROM payroll_run_employments
              WHERE supplier_id = ? AND revision_id = ?',
        );
        $stmt->execute([$supplierId, $revisionId]);
        $owners = [];
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$employmentId, $employeeId]) {
            $owners[(int) $employmentId] = (int) $employeeId;
        }

        return $owners;
    }

    /**
     * Celý výsledek jedné osoby, tak jak ho nese výsledkový snímek revize
     * v `people[]` (rozpis pásky, zákonný výsledek, srážky).
     *
     * @return array{full_name:string,result:array<string,mixed>}|null
     */
    public function person(int $supplierId, int $revisionId, int $employeeId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . PayrollPeopleRepository::fullNameExpression('employee') . ' AS full_name,
                    person.result_json
               FROM payroll_run_persons person
               JOIN payroll_employees employee
                 ON employee.supplier_id = person.supplier_id
                AND employee.id = person.employee_id
              WHERE person.supplier_id = ?
                AND person.revision_id = ?
                AND person.employee_id = ?
                AND person.result_json IS NOT NULL',
        );
        $stmt->execute([$supplierId, $revisionId, $employeeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $result = json_decode((string) $row['result_json'], true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($result)) {
            return null;
        }

        return ['full_name' => (string) $row['full_name'], 'result' => $result];
    }

    private static function orderBy(string $sort, bool $descending): string
    {
        $direction = $descending ? 'DESC' : 'ASC';
        $names = PayrollPeopleRepository::nameSortExpressions('employee');
        $byName = "{$names['last_name']} %1\$s, ({$names['first_name']}) IS NULL ASC, {$names['first_name']} %1\$s";
        if (!isset(self::AMOUNT_SORTS[$sort])) {
            return ' ORDER BY ' . sprintf($byName, $direction) . ', person.employee_id ASC';
        }
        $expression = self::AMOUNT_SORTS[$sort];

        return " ORDER BY {$expression} IS NULL ASC, {$expression} {$direction}, "
            . sprintf($byName, 'ASC') . ', person.employee_id ASC';
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(
            [self::LIKE_ESCAPE, '%', '_'],
            [self::LIKE_ESCAPE . self::LIKE_ESCAPE, self::LIKE_ESCAPE . '%', self::LIKE_ESCAPE . '_'],
            $value,
        );
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function castItem(array $row): array
    {
        $minor = static fn (string $key): ?int => $row[$key] === null ? null : (int) $row[$key];
        $sum = static function (string $left, string $right) use ($minor): ?int {
            $a = $minor($left);
            $b = $minor($right);

            return $a === null && $b === null ? null : ($a ?? 0) + ($b ?? 0);
        };

        return [
            'employee_id' => (int) $row['employee_id'],
            'full_name' => (string) $row['full_name'],
            'result_status' => $row['result_status'],
            'gross_minor' => $minor('gross_minor'),
            'employee_social_minor' => $minor('employee_social_minor'),
            'employee_health_minor' => $minor('employee_health_minor'),
            'insurance_minor' => $sum('employee_social_minor', 'employee_health_minor'),
            'advance_tax_minor' => $minor('advance_tax_minor'),
            'withholding_tax_minor' => $minor('withholding_tax_minor'),
            'tax_minor' => $sum('advance_tax_minor', 'withholding_tax_minor'),
            'tax_bonus_minor' => $minor('tax_bonus_minor'),
            'net_minor' => $minor('net_minor'),
            'payable_minor' => $minor('payable_minor'),
        ];
    }
}
