<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class PayrollPeopleRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly PayrollEmploymentRepository $employments,
        private readonly PayrollEmployeeDeletionRepository $deletion,
    ) {}

    /**
     * Strop stránky seznamu osob. Seznam je pracovní tabulka, ne číselník —
     * uživatel v něm zakládá, opravuje a maže, takže dvě stě řádků naráz je
     * horní hranice toho, co má smysl posílat. Výběr osoby do rozbalovátka
     * potřebuje něco jiného a jede přes {@see self::listOptionsForTenant()}.
     */
    public const LIST_MAX_LIMIT = 200;

    public const LIST_DEFAULT_LIMIT = 50;

    /**
     * Povolené zúžení seznamu. `all` je jediná hodnota, která nic neschovává —
     * proto je i výchozí pro volající, kteří filtr neřeší.
     */
    public const LIST_FILTERS = ['all', 'active', 'needs_setup'];

    public const LIST_DEFAULT_FILTER = 'all';

    /**
     * Znak, kterým se v hledání escapuje `%`, `_` a on sám. Zpětné lomítko by
     * se cestou přes PHP a SQL literál zdvojovalo, tenhle znak ne.
     */
    private const LIKE_ESCAPE = '!';

    /**
     * Stránka seznamu osob s tvrdým stropem.
     *
     * Na KAŽDÝ řádek připadá rozhodnutí o smazatelnosti
     * ({@see self::withDeletion()}), a to stojí vlastní dotazy. Bez stropu tedy
     * jeden požadavek vyrobil tolik těžkých dotazů, kolik má firma zaměstnanců.
     * Strop se ořezává i tady, ne jen na hraně HTTP — repozitář nesmí spoléhat
     * na to, že ho volající ořízl za něj.
     *
     * Filtr a hledání se uplatní na stránku i na `total`. Kdyby platily jen na
     * stránku, pager by nabízel stránky, na kterých po zúžení nikdo není.
     * Neznámý filtr se chová jako `all`; na hraně HTTP ho odmítá akce.
     *
     * @return array{items: list<array<string,mixed>>, total: int}
     */
    public function listForTenant(
        int $supplierId,
        int $limit = self::LIST_DEFAULT_LIMIT,
        int $offset = 0,
        string $filter = self::LIST_DEFAULT_FILTER,
        string $search = '',
    ): array {
        $limit = max(1, min(self::LIST_MAX_LIMIT, $limit));
        $offset = max(0, $offset);
        $narrowing = $this->narrowingClause($filter, $search);

        $countStmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) ' . self::fromClause()
            . ' WHERE employee.supplier_id = ?' . $narrowing['sql'],
        );
        $position = 1;
        $countStmt->bindValue($position++, $supplierId, PDO::PARAM_INT);
        $countStmt->bindValue($position++, $supplierId, PDO::PARAM_INT);
        foreach ($narrowing['params'] as $value) {
            $countStmt->bindValue($position++, $value);
        }
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->peopleQuery(paged: true, narrowing: $narrowing['sql']);
        $position = 1;
        $stmt->bindValue($position++, $supplierId, PDO::PARAM_INT);
        $stmt->bindValue($position++, $supplierId, PDO::PARAM_INT);
        foreach ($narrowing['params'] as $value) {
            $stmt->bindValue($position++, $value);
        }
        $stmt->bindValue($position++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($position, $offset, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $person = $this->castPerson($this->normalizeRow($row));
            $items[] = $this->withDeletion($supplierId, $person);
        }

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Zúžení seznamu — filtr a hledání pohromadě, aby je stránka i počítadlo
     * dostaly ZAROVEŇ. Rozdělené by se dřív nebo později rozešly a pager by
     * hlásil jiné číslo, než kolik jde odklikat.
     *
     * @return array{sql: string, params: list<string>}
     */
    private function narrowingClause(string $filter, string $search): array
    {
        $sql = '';
        $params = [];

        if ($filter === 'active') {
            $sql .= ' AND employee.is_active = 1';
        } elseif ($filter === 'needs_setup') {
            $sql .= ' AND ' . self::needsSetupExpression();
        }

        $search = trim($search);
        if ($search !== '') {
            // Hledá se v účinném jméně, ne ve sloupci — po přejmenování osoby
            // by ji hledání podle sloupce přestalo najít.
            $sql .= ' AND ' . self::fullNameExpression()
                . " LIKE ? ESCAPE '" . self::LIKE_ESCAPE . "'";
            $params[] = '%' . self::escapeLike($search) . '%';
        }

        return ['sql' => $sql, 'params' => $params];
    }

    /**
     * Napsané `%` a `_` jsou hledaný text, ne zástupné znaky — jinak by `_`
     * našlo cokoli a `%` úplně všechny.
     */
    private static function escapeLike(string $value): string
    {
        return str_replace(
            [self::LIKE_ESCAPE, '%', '_'],
            [self::LIKE_ESCAPE . self::LIKE_ESCAPE, self::LIKE_ESCAPE . '%', self::LIKE_ESCAPE . '_'],
            $value,
        );
    }

    /**
     * Co osobě chybí, aby na ni šlo spustit mzdy — jedna podmínka na mezeru.
     *
     * Dřív se odvozovalo z `profile.profile_status`, jenže ten je RUČNĚ přepínaný
     * ENUM (`legacy | setup | ready`). Osoba s kompletním profilem tak svítila
     * „Vyžaduje doplnění" jen proto, že nikdo přepínač nepřehodil, a štítek
     * neuměl říct co chybí, protože nevěděl nic než že hodnota není `ready`.
     *
     * Podmínky jsou schválně TYTÉŽ čtyři, které vynucuje
     * {@see PayrollPersonProfileRepository::assertReadyProfile()}. Kdyby se
     * rozešly, jedna obrazovka by nabízela doplnit něco, co druhá odmítne uložit.
     *
     * @return array<string,string>
     */
    private static function setupGapExpressions(): array
    {
        return [
            'name' => "NOT EXISTS (
                SELECT 1 FROM payroll_person_identity_history gap
                 WHERE gap.supplier_id = employee.supplier_id
                   AND gap.employee_id = employee.id
                   AND gap.first_name IS NOT NULL AND gap.first_name <> ''
                   AND gap.last_name IS NOT NULL AND gap.last_name <> ''
                   AND gap.effective_from <= CURRENT_DATE
                   AND (gap.effective_to IS NULL OR gap.effective_to >= CURRENT_DATE))",
            'residence' => "NOT EXISTS (
                SELECT 1 FROM payroll_person_addresses gap
                 WHERE gap.supplier_id = employee.supplier_id
                   AND gap.employee_id = employee.id
                   AND gap.address_type = 'residence'
                   AND gap.effective_from <= CURRENT_DATE
                   AND (gap.effective_to IS NULL OR gap.effective_to >= CURRENT_DATE))",
            'contact' => 'NOT EXISTS (
                SELECT 1 FROM payroll_person_contacts gap
                 WHERE gap.supplier_id = employee.supplier_id
                   AND gap.employee_id = employee.id
                   AND gap.is_active = 1 AND gap.is_primary = 1)',
            'identifier' => "NOT EXISTS (
                SELECT 1 FROM payroll_person_identifiers gap
                 WHERE gap.supplier_id = employee.supplier_id
                   AND gap.employee_id = employee.id
                   AND gap.identifier_type IN ('birth_number', 'ecp', 'vcp'))",
            'employment' => 'NOT EXISTS (
                SELECT 1 FROM payroll_employments gap
                 WHERE gap.supplier_id = employee.supplier_id
                   AND gap.employee_id = employee.id)',
        ];
    }

    /** Sloupce `setup_gap_*`, ze kterých karta poskládá výčet chybějícího. */
    private static function setupGapColumns(): string
    {
        $columns = [];
        foreach (self::setupGapExpressions() as $key => $expression) {
            $columns[] = "({$expression}) AS setup_gap_{$key}";
        }

        return implode(",\n                   ", $columns);
    }

    /**
     * Tatáž podmínka, jakou nese vrácené pole `needs_setup`
     * ({@see self::castPerson()}) — postavená nad TÝMIŽ výrazy, které vybírá
     * SELECT. Filtr a příznak na řádku se tak nemůžou rozejít.
     */
    private static function needsSetupExpression(): string
    {
        return '(' . implode(' OR ', array_map(
            static fn (string $expression): string => "({$expression})",
            array_values(self::setupGapExpressions()),
        )) . ')';
    }

    /**
     * Lehký výběr osob pro rozbalovátka — JEDINÝ dotaz, žádné rozhodnutí
     * o smazatelnosti a žádné doprovodné počty.
     *
     * Vrací se úmyslně celý a bez stránkování: cena je konstantní bez ohledu na
     * počet zaměstnanců, takže stránkovat by znamenalo jen víc kol dokola.
     * Řazení je stejné jako v seznamu, aby se obě obrazovky shodly na pořadí.
     *
     * `needs_setup` tu je proto, že rozbalovátko dokladů jím značí osoby
     * s nedodělaným profilem. Víc polí sem nepatří — co si nabídka nepřečte,
     * to se neposílá.
     *
     * @return list<array{id:int,full_name:string,is_active:bool,needs_setup:bool}>
     */
    public function listOptionsForTenant(int $supplierId): array
    {
        // Tentýž výraz jako stránkovaný seznam — jinak by nabídka značila jiné
        // osoby než přehled a uživatel by nevěděl, které ze dvou čísel platí.
        $stmt = $this->db->pdo()->prepare(
            'SELECT employee.id,
                    ' . self::fullNameExpression() . ' AS full_name,
                    employee.is_active,
                    ' . self::needsSetupExpression() . ' AS needs_setup
               FROM payroll_employees employee
              WHERE employee.supplier_id = ?
              ORDER BY employee.is_active DESC, full_name ASC, employee.id ASC',
        );
        $stmt->execute([$supplierId]);

        $options = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $option = $this->normalizeRow($row);
            $options[] = [
                'id' => $this->intValue($option, 'id'),
                'full_name' => $this->stringValue($option, 'full_name'),
                'is_active' => $this->boolValue($option, 'is_active'),
                'needs_setup' => $this->boolValue($option, 'needs_setup'),
            ];
        }

        return $options;
    }

    /**
     * @param list<int> $employeeIds
     * @return array<int,string>
     */
    public function namesForTenant(int $supplierId, array $employeeIds): array
    {
        $ids = [];
        foreach ($employeeIds as $employeeId) {
            if ($employeeId <= 0) {
                throw new \InvalidArgumentException('Identifikátor zaměstnance musí být kladný.');
            }
            $ids[$employeeId] = true;
        }
        $ids = array_keys($ids);
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->db->pdo()->prepare(
            'SELECT employee.id,
                    ' . self::fullNameExpression() . ' AS full_name
               FROM payroll_employees employee
              WHERE employee.supplier_id = ?
                AND employee.id IN (' . $placeholders . ')',
        );
        $statement->execute([$supplierId, ...$ids]);

        $names = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                throw new \UnexpectedValueException('Databáze vrátila neplatného zaměstnance.');
            }
            $names[(int) $row['id']] = (string) $row['full_name'];
        }

        return $names;
    }

    /** @return array<string,mixed>|null */
    public function findForTenant(int $supplierId, int $employeeId): ?array
    {
        $stmt = $this->peopleQuery(true);
        $stmt->execute([$supplierId, $supplierId, $employeeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $person = $this->withDeletion(
            $supplierId,
            $this->castPerson($this->normalizeRow($row)),
        );
        $person['employments'] = $this->employments->listForEmployee($supplierId, $employeeId);

        return $person;
    }

    /**
     * `can_delete` musí být v seznamu i v detailu — jinak by frontend nabízel akci
     * naslepo a důvod blokace by se dozvěděl až po kliknutí. Cizí tenant sem
     * nedosáhne: rozhodnutí se počítá jen pro osoby vrácené tenantovým dotazem.
     *
     * @param array<string,mixed> $person
     * @return array<string,mixed>
     */
    private function withDeletion(int $supplierId, array $person): array
    {
        $employeeId = $person['id'];
        $decision = is_int($employeeId)
            ? $this->deletion->canDelete($supplierId, $employeeId)
            : null;
        $person['can_delete'] = $decision !== null && $decision->canDelete;
        $person['delete_blocker'] = $decision?->blockerPayload();
        $person['delete_cascade'] = $decision === null ? [] : $decision->cascade;

        return $person;
    }

    /**
     * Účinné jméno osoby z historie identit s pádem zpět na `payroll_employees`.
     * Sdílí ho seznam i lehký výběr, aby se obě cesty nemohly rozejít.
     *
     * Je VEŘEJNÉ, protože jméno potřebuje i retenční posudek — a pravidlo, které
     * jde jen opsat, se opíše: seznam osob by po změně příjmení ukazoval nové
     * jméno a návrh k výmazu staré, přestože mluví o téže osobě. `$alias` je
     * název tabulky `payroll_employees` v dotazu volajícího, ne uživatelský vstup.
     */
    public static function fullNameExpression(string $alias = 'employee'): string
    {
        return sprintf(
            <<<'SQL'
            COALESCE(
                       (
                           SELECT identity_history.full_name
                             FROM payroll_person_identity_history identity_history
                            WHERE identity_history.supplier_id = %1$s.supplier_id
                              AND identity_history.employee_id = %1$s.id
                              AND identity_history.effective_from <= CURRENT_DATE
                              AND (
                                  identity_history.effective_to IS NULL
                                  OR identity_history.effective_to >= CURRENT_DATE
                              )
                            ORDER BY identity_history.effective_from DESC,
                                     identity_history.id DESC
                            LIMIT 1
                       ),
                       %1$s.full_name
                   )
            SQL,
            $alias,
        );
    }

    /**
     * Zdroj řádků seznamu. Sdílí ho výpis i počítadlo, aby obě cesty vážily
     * tytéž osoby stejnými výrazy. Nese jeden parametr — firmu v poddotazu
     * pracovních vztahů.
     */
    private static function fromClause(): string
    {
        return <<<'SQL'
            FROM payroll_employees employee
              LEFT JOIN payroll_employee_profiles profile
                ON profile.supplier_id = employee.supplier_id
               AND profile.employee_id = employee.id
              LEFT JOIN (
                    SELECT supplier_id,
                           employee_id,
                           COUNT(*) AS employment_count,
                           GROUP_CONCAT(DISTINCT relation_type ORDER BY relation_type SEPARATOR ',') AS relation_types,
                           GROUP_CONCAT(
                               CONCAT_WS(
                                   CHAR(31 USING utf8mb4),
                                   id,
                                   REPLACE(
                                       REPLACE(code, CHAR(31 USING utf8mb4), ' '),
                                       CHAR(10 USING utf8mb4),
                                       ' '
                                   ),
                                   relation_type,
                                   status,
                                   is_primary
                               )
                               ORDER BY is_primary DESC, id ASC
                               SEPARATOR '\n'
                           ) AS employment_refs
                      FROM payroll_employments
                     WHERE supplier_id = ?
                     GROUP BY supplier_id, employee_id
              ) relations
                ON relations.supplier_id = employee.supplier_id
               AND relations.employee_id = employee.id
            SQL;
    }

    private function peopleQuery(
        bool $single = false,
        bool $paged = false,
        string $narrowing = '',
    ): \PDOStatement {
        $sql = 'SELECT employee.id,
                   ' . self::fullNameExpression() . <<<'SQL'
             AS full_name,
                   employee.is_active,
                   profile.profile_status,
                   employee.taxpayer_type AS legacy_taxpayer_type,
                   employee.employment_type AS legacy_employment_type,
                   COALESCE(relations.employment_count, 0) AS employment_count,
                   COALESCE(relations.relation_types, '') AS relation_types,
                   COALESCE(relations.employment_refs, '') AS employment_refs,
            SQL
            . "\n                   " . self::setupGapColumns()
            . ' ' . self::fromClause() . ' WHERE employee.supplier_id = ?';
        if ($single) {
            $sql .= ' AND employee.id = ?';
        }
        $sql .= $narrowing;
        $sql .= ' ORDER BY employee.is_active DESC, full_name ASC, employee.id ASC';
        if ($paged) {
            $sql .= ' LIMIT ? OFFSET ?';
        }

        return $this->db->pdo()->prepare($sql);
    }

    /**
     * @param array<string,string|int|bool|null> $row
     * @return array<string,mixed>
     */
    private function castPerson(array $row): array
    {
        $profileStatus = $row['profile_status'] === null
            ? 'missing'
            : $this->stringValue($row, 'profile_status');
        $employmentCount = $this->intValue($row, 'employment_count');
        $relationTypes = $row['relation_types'] === ''
            ? []
            : explode(',', $this->stringValue($row, 'relation_types'));
        // Štítek „Vyžaduje doplnění" má JMENOVAT, co chybí — prázdný štítek
        // uživatele jen posílal hádat po celé kartě.
        $setupGaps = [];
        foreach (array_keys(self::setupGapExpressions()) as $gap) {
            if ($this->boolValue($row, "setup_gap_{$gap}")) {
                $setupGaps[] = $gap;
            }
        }

        return [
            'id' => $this->intValue($row, 'id'),
            'full_name' => $this->stringValue($row, 'full_name'),
            'is_active' => $this->boolValue($row, 'is_active'),
            'profile_status' => $profileStatus,
            'legacy_taxpayer_type' => $this->stringValue($row, 'legacy_taxpayer_type'),
            'legacy_employment_type' => $this->stringValue($row, 'legacy_employment_type'),
            'employment_count' => $employmentCount,
            'relation_types' => $relationTypes,
            'employment_refs' => $this->employmentRefs($this->stringValue($row, 'employment_refs')),
            'setup_gaps' => $setupGaps,
            'needs_setup' => $setupGaps !== [],
        ];
    }

    /**
     * Pracovní vztahy osoby pro rozcestník seznamu.
     *
     * Why: rychlé akce v řádku (docházka, nepřítomnosti, mzdové vstupy) se zužují
     * na `employment_id`, ne na osobu — a to seznam neznal, takže by na každý
     * řádek potřeboval vlastní dotaz. Jede to proto v TÉMŽE poddotazu, který už
     * počítá `employment_count`; seznam tak nestojí ani jeden dotaz navíc.
     *
     * Sbaleno GROUP_CONCATem: pole odděluje znak US (31), záznamy nový řádek —
     * `SEPARATOR` bere jen literál, výraz `CHAR(30)` tam MariaDB nepustí. `code`
     * je volný text uživatele, takže se mu oba oddělovače v SQL nahradí mezerou;
     * s čárkou nebo dvojtečkou by rozpad tiše rozhodil celé pole. Záznam, který
     * po rozpadu nemá pět částí, se zahazuje — chybný odkaz je horší než žádný.
     *
     * @return list<array<string,mixed>>
     */
    private function employmentRefs(string $packed): array
    {
        if ($packed === '') {
            return [];
        }

        $refs = [];
        foreach (explode("\n", $packed) as $record) {
            $parts = explode("\x1f", $record);
            if (count($parts) !== 5) {
                continue;
            }
            [$id, $code, $relationType, $status, $isPrimary] = $parts;
            $refs[] = [
                'id' => (int) $id,
                'code' => $code,
                'relation_type' => $relationType,
                'status' => $status,
                'is_primary' => $isPrimary === '1',
            ];
        }

        return $refs;
    }

    /** @return array<string,string|int|bool|null> */
    private function normalizeRow(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException('Databáze vrátila neplatný řádek zaměstnance.');
        }

        $row = [];
        foreach ($value as $key => $cell) {
            if (!is_string($key)
                || (!is_string($cell) && !is_int($cell) && !is_bool($cell) && $cell !== null)
            ) {
                throw new \UnexpectedValueException('Databáze vrátila neplatnou hodnotu zaměstnance.');
            }
            $row[$key] = $cell;
        }

        return $row;
    }

    /** @param array<string,string|int|bool|null> $row */
    private function stringValue(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_bool($value)) {
            return (string) (int) $value;
        }

        throw new \UnexpectedValueException("Databázové pole {$key} není řetězec.");
    }

    /** @param array<string,string|int|bool|null> $row */
    private function intValue(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_string($value)) {
            $validated = filter_var($value, FILTER_VALIDATE_INT);
            if (is_int($validated)) {
                return $validated;
            }
        }

        throw new \UnexpectedValueException("Databázové pole {$key} není celé číslo.");
    }

    /** @param array<string,string|int|bool|null> $row */
    private function boolValue(array $row, string $key): bool
    {
        $value = $row[$key] ?? null;
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === '0') {
            return false;
        }
        if ($value === 1 || $value === '1') {
            return true;
        }

        throw new \UnexpectedValueException("Databázové pole {$key} není boolean.");
    }
}
