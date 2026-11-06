<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\People\PayrollPersonDataGapCatalog;
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
     *
     * `needs_setup` je STARŠÍ, užší pohled (pět podmínek uložení profilu);
     * zůstává, aby staré odkazy nespadly. Nové obrazovky zužují přes
     * `needs_data` (cokoli chybí) a `blocking_data` (chybí něco, bez čeho
     * měsíc neprojde) — obojí nad {@see PayrollPersonDataGapCatalog}.
     */
    public const LIST_FILTERS = [
        'all',
        'active',
        'needs_setup',
        'needs_data',
        'blocking_data',
        self::FILTER_DPP_HOURS_LIMIT,
    ];

    /**
     * Osoby, které na DPP v roce dosáhly prahu upozornění nebo překročily
     * limit 300 h. Součet hodin SQL neumí (převzaté měsíce, hranice začátku
     * vedení mezd), proto množinu osob dodává volající z
     * {@see \MyInvoice\Service\Payroll\Time\PayrollAgreementAnnualHours::employeesNearLimit()}.
     */
    public const FILTER_DPP_HOURS_LIMIT = 'dpp_hours_limit';

    public const LIST_DEFAULT_FILTER = 'all';

    /**
     * Řazení seznamu podle sloupce. Klíč je klíč sloupce na obrazovce, hodnota
     * výraz nad odvozenou tabulkou `people` ({@see self::peopleQuery()}).
     * Výchozí je příjmení a jméno: tak účetní lidi hledá. Osoba bez
     * strukturovaného jména se řadí podle celého jména, aby nepropadla na
     * začátek ani na konec.
     *
     * Jména se řadí českou collation (Š za S, Ch za H), sloupce samy jsou
     * v obecné `unicode_ci`, která háčky přehlíží. Osobní čísla přirozeně
     * (`Z2` před `Z10`).
     */
    public const LIST_SORTS = [
        'last_name' => "COALESCE(NULLIF(people.last_name, ''), people.full_name) COLLATE utf8mb4_czech_ci",
        'first_name' => "NULLIF(people.first_name, '') COLLATE utf8mb4_czech_ci",
        'full_name' => 'people.full_name COLLATE utf8mb4_czech_ci',
        'personal_number' => "NATURAL_SORT_KEY(NULLIF(people.primary_code, ''))",
        'status' => 'people.is_active',
        'relations' => "NULLIF(people.relation_types, '')",
        'count' => 'people.employment_count',
        'start_date' => 'people.first_start_on',
        'end_date' => 'people.last_end_on',
        'birth_date' => 'people.birth_date',
        // Názvy pozic jsou v číselníku mimo databázi, řadí se proto podle kódu
        // CZ-ISCO; příbuzná zaměstnání tak stojí u sebe.
        'position' => 'people.cz_isco_code',
    ];

    public const LIST_DEFAULT_SORT = 'last_name';

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
     * Neznámý klíč řazení se chová jako výchozí příjmení a jméno; odmítá ho akce.
     *
     * @param list<int> $employeeIds množina osob pro filtr {@see self::FILTER_DPP_HOURS_LIMIT}
     * @return array{items: list<array<string,mixed>>, total: int}
     */
    public function listForTenant(
        int $supplierId,
        int $limit = self::LIST_DEFAULT_LIMIT,
        int $offset = 0,
        string $filter = self::LIST_DEFAULT_FILTER,
        string $search = '',
        array $employeeIds = [],
        string $sortKey = self::LIST_DEFAULT_SORT,
        bool $descending = false,
    ): array {
        $limit = max(1, min(self::LIST_MAX_LIMIT, $limit));
        $offset = max(0, $offset);
        $narrowing = $this->narrowingClause($filter, $search, $employeeIds);

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

        $stmt = $this->peopleQuery(
            paged: true,
            narrowing: $narrowing['sql'],
            orderBy: self::orderByClause($sortKey, $descending),
        );
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
     * @param list<int> $employeeIds
     * @return array{sql: string, params: list<string>}
     */
    private function narrowingClause(string $filter, string $search, array $employeeIds = []): array
    {
        $sql = '';
        $params = [];

        if ($filter === 'active') {
            $sql .= ' AND employee.is_active = 1';
        } elseif ($filter === 'needs_setup') {
            $sql .= ' AND ' . self::needsSetupExpression();
        } elseif ($filter === 'needs_data') {
            $sql .= ' AND ' . PayrollPersonDataGapCatalog::gapExpression();
        } elseif ($filter === 'blocking_data') {
            $sql .= ' AND ' . PayrollPersonDataGapCatalog::gapExpression(
                PayrollPersonDataGapCatalog::SEVERITY_BLOCKING,
            );
        } elseif ($filter === self::FILTER_DPP_HOURS_LIMIT) {
            if ($employeeIds === []) {
                $sql .= ' AND 1 = 0';
            } else {
                $sql .= ' AND employee.id IN (' . implode(', ', array_fill(0, count($employeeIds), '?')) . ')';
                foreach ($employeeIds as $employeeId) {
                    $params[] = (string) $employeeId;
                }
            }
        }

        $search = trim($search);
        if ($search !== '') {
            // Hledá se v účinném jméně, ne ve sloupci — po přejmenování osoby
            // by ji hledání podle sloupce přestalo najít. Osobní číslo je kód
            // pracovního vztahu; osoba se najde podle kteréhokoli svého vztahu.
            $sql .= ' AND (' . self::fullNameExpression()
                . " LIKE ? ESCAPE '" . self::LIKE_ESCAPE . "'"
                . ' OR EXISTS (SELECT 1 FROM payroll_employments search_employment'
                . ' WHERE search_employment.supplier_id = employee.supplier_id'
                . ' AND search_employment.employee_id = employee.id'
                . " AND search_employment.code LIKE ? ESCAPE '" . self::LIKE_ESCAPE . "'))";
            $params[] = '%' . self::escapeLike($search) . '%';
            $params[] = '%' . self::escapeLike($search) . '%';
        }

        return ['sql' => $sql, 'params' => $params];
    }

    /**
     * ORDER BY z bílé listiny {@see self::LIST_SORTS}; do SQL nejde nic z URL.
     * Prázdné hodnoty jdou v obou směrech na konec (osoba bez data nástupu
     * nemá stát nad všemi ostatními). Příjmení, jméno a id na konci drží
     * pořadí stabilní, aby stránky na sebe navazovaly.
     */
    private static function orderByClause(string $sortKey, bool $descending): string
    {
        if (!isset(self::LIST_SORTS[$sortKey])) {
            $sortKey = self::LIST_DEFAULT_SORT;
        }
        $direction = $descending ? 'DESC' : 'ASC';
        $expression = self::LIST_SORTS[$sortKey];
        $lastName = self::LIST_SORTS['last_name'];
        $firstName = self::LIST_SORTS['first_name'];
        $parts = ["({$expression}) IS NULL ASC", "{$expression} {$direction}"];
        if ($sortKey === 'last_name') {
            array_push($parts, "({$firstName}) IS NULL ASC", "{$firstName} {$direction}");
        } elseif ($sortKey === 'first_name') {
            $parts[] = "{$lastName} ASC";
        } else {
            array_push($parts, "{$lastName} ASC", "({$firstName}) IS NULL ASC", "{$firstName} ASC");
        }
        $parts[] = 'people.id ASC';

        return ' ORDER BY ' . implode(', ', $parts);
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
     * Podmínky BYLY opsané tady; teď je drží {@see PayrollPersonDataGapCatalog}
     * spolu se zbytkem toho, co osobě může chybět. Užší pohled `setup_gaps`
     * zůstává (a je pořád tatáž pětice, kterou vynucuje
     * {@see PayrollPersonProfileRepository::assertReadyProfile()}), jen se
     * vybírá z katalogu, aby existoval jeden seznam pravidel místo dvou.
     *
     * @return array<string,string>
     */
    private static function setupGapExpressions(): array
    {
        $expressions = PayrollPersonDataGapCatalog::expressions();
        $legacy = [];
        foreach (PayrollPersonDataGapCatalog::legacySetupKeys() as $key) {
            $legacy[$key] = $expressions[$key];
        }

        return $legacy;
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
    /**
     * Aktivní osoby, kterým chybí něco, bez čeho měsíc neprojde.
     *
     * Čte se z TÝCHŽ výrazů jako štítek v seznamu ({@see PayrollPersonDataGapCatalog}),
     * takže kontrola před zahájením běhu nemůže tvrdit něco jiného než seznam
     * lidí. Vrací jen jména a identifikátory — nález má člověka JMENOVAT, ne
     * hlásit číslo, se kterým se nedá nic dělat.
     *
     * Neaktivní osoby se vynechávají: za toho, kdo odešel, se nic nepodává, a
     * jeho nedodělaná karta by nález zaplevelila navždycky.
     *
     * @return list<array{id:int,full_name:string}>
     */
    public function listActiveWithBlockingDataGaps(int $supplierId, int $limit = 25): array
    {
        return $this->activeWithBlockingDataGaps($supplierId, $limit)['people'];
    }

    /**
     * Totéž co {@see self::listActiveWithBlockingDataGaps()}, navíc se SKUTEČNÝM
     * počtem dotčených osob.
     *
     * Seznam je oříznutý na `$limit` jmen, ale počet z něj brát nejde: kontrola
     * před během pak u firmy s 225 lidmi bez údajů hlásila „25×". Celkový počet
     * se proto počítá v témže dotazu oknem přes celý výsledek, ještě před LIMIT.
     *
     * @return array{total:int,people:list<array{id:int,full_name:string}>}
     */
    public function activeWithBlockingDataGaps(int $supplierId, int $limit = 25): array
    {
        $limit = max(1, $limit);
        $stmt = $this->db->pdo()->prepare(
            'SELECT employee.id,
                    ' . self::fullNameExpression() . ' AS full_name,
                    COUNT(*) OVER () AS total_count'
            . ' ' . self::fromClause()
            . ' WHERE employee.supplier_id = ?
                AND employee.is_active = 1
                AND ' . PayrollPersonDataGapCatalog::gapExpression(
                PayrollPersonDataGapCatalog::SEVERITY_BLOCKING,
            )
            . ' ORDER BY full_name ASC, employee.id ASC
               LIMIT ?',
        );
        $stmt->bindValue(1, $supplierId, PDO::PARAM_INT);
        $stmt->bindValue(2, $supplierId, PDO::PARAM_INT);
        $stmt->bindValue(3, $limit, PDO::PARAM_INT);
        $stmt->execute();

        $people = [];
        $total = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $person = $this->normalizeRow($row);
            $total = $this->intValue($person, 'total_count');
            $people[] = [
                'id' => $this->intValue($person, 'id'),
                'full_name' => $this->stringValue($person, 'full_name'),
            ];
        }

        return ['total' => max($total, count($people)), 'people' => $people];
    }

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
     * Osoby podle jména nebo osobního čísla ve více firmách naráz — pro globální
     * hledání (Alt+Q). Hledá jen ve firmách se zapnutými mzdami; o tom, zda
     * smí uživatel výsledek firmy vidět, rozhoduje VOLAJÍCÍ (tady se jen hledá).
     * Každé slovo dotazu musí sedět na jméno nebo osobní číslo, takže najde
     * „Novák Petr" i „Petr Novák".
     *
     * @param list<int> $supplierIds
     * @return list<array{id:int,supplier_id:int,full_name:string,is_active:bool,code:?string}>
     */
    public function searchAcrossSuppliers(array $supplierIds, string $search, int $preferredSupplierId, int $limit): array
    {
        $supplierIds = array_values(array_unique(array_filter($supplierIds, static fn (int $id): bool => $id > 0)));
        $tokens = array_slice(array_values(array_filter(
            preg_split('/\s+/u', trim($search)) ?: [],
            static fn (string $token): bool => $token !== '',
        )), 0, 4);
        if ($supplierIds === [] || $tokens === []) {
            return [];
        }

        $params = $supplierIds;
        $conditions = [];
        foreach ($tokens as $token) {
            $conditions[] = '(' . self::fullNameExpression()
                . " LIKE ? ESCAPE '" . self::LIKE_ESCAPE . "'"
                . ' OR EXISTS (SELECT 1 FROM payroll_employments search_employment'
                . ' WHERE search_employment.supplier_id = employee.supplier_id'
                . ' AND search_employment.employee_id = employee.id'
                . " AND search_employment.code LIKE ? ESCAPE '" . self::LIKE_ESCAPE . "'))";
            $like = '%' . self::escapeLike(mb_substr($token, 0, 100)) . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $params[] = $preferredSupplierId;

        $stmt = $this->db->pdo()->prepare(
            'SELECT employee.id, employee.supplier_id, employee.is_active,
                    ' . self::fullNameExpression() . ' AS full_name,
                    (SELECT employment.code
                       FROM payroll_employments employment
                      WHERE employment.supplier_id = employee.supplier_id
                        AND employment.employee_id = employee.id
                      ORDER BY employment.is_primary DESC, employment.id ASC
                      LIMIT 1) AS code
               FROM payroll_employees employee
               JOIN supplier ON supplier.id = employee.supplier_id AND supplier.payroll_enabled = 1
              WHERE employee.supplier_id IN (' . implode(',', array_fill(0, count($supplierIds), '?')) . ')
                AND ' . implode(' AND ', $conditions) . '
              ORDER BY employee.supplier_id = ? DESC, employee.is_active DESC,
                       full_name COLLATE utf8mb4_czech_ci ASC, employee.id ASC
              LIMIT ' . max(1, min(100, $limit)),
        );
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $person = $this->normalizeRow($row);
            $out[] = [
                'id' => $this->intValue($person, 'id'),
                'supplier_id' => $this->intValue($person, 'supplier_id'),
                'full_name' => $this->stringValue($person, 'full_name'),
                'is_active' => $this->boolValue($person, 'is_active'),
                'code' => $this->nullableStringValue($person, 'code'),
            ];
        }

        return $out;
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
            'COALESCE(%s, %s.full_name)',
            self::currentIdentityExpression('full_name', $alias),
            $alias,
        );
    }

    /**
     * Sloupec účinného záznamu historie identit. Tentýž výběr záznamu jako
     * {@see self::fullNameExpression()}, aby jméno, příjmení i celé jméno
     * pocházely vždy z téže platné identity. `$column` je pevný název sloupce
     * z tohoto repozitáře, ne vstup.
     */
    private static function currentIdentityExpression(string $column, string $alias = 'employee'): string
    {
        return sprintf(
            <<<'SQL'
            (
                           SELECT identity_history.%2$s
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
                       )
            SQL,
            $alias,
            $column,
        );
    }

    /**
     * Popisky vztahů „Jméno (kód)" pro nálezy a hlášky. Jediné místo, odkud je
     * berou předběžná kontrola běhu, potlačení varování i blokace JMHZ — jinak
     * by se každá kopie po změně jména nebo kódu rozešla s ostatními.
     *
     * @param list<int> $ids
     * @return array<int,string>
     */
    public function employmentLabels(int $supplierId, array $ids): array
    {
        $labels = [];
        foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->db->pdo()->prepare(
                'SELECT employment.id, employment.code,
                        ' . self::fullNameExpression() . ' AS full_name
                   FROM payroll_employments employment
                   JOIN payroll_employees employee
                     ON employee.id = employment.employee_id
                    AND employee.supplier_id = employment.supplier_id
                  WHERE employment.supplier_id = ? AND employment.id IN (' . $placeholders . ')'
            );
            $stmt->execute([$supplierId, ...$chunk]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $code = (string) ($row['code'] ?? '');
                $labels[(int) $row['id']] = $code === ''
                    ? (string) $row['full_name']
                    : sprintf('%s (%s)', (string) $row['full_name'], $code);
            }
        }

        return $labels;
    }

    /**
     * Účinná jména osob podle id zaměstnance.
     *
     * @param list<int> $ids
     * @return array<int,string>
     */
    public function personLabels(int $supplierId, array $ids): array
    {
        $labels = [];
        foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->db->pdo()->prepare(
                'SELECT employee.id, ' . self::fullNameExpression() . ' AS full_name
                   FROM payroll_employees employee
                  WHERE employee.supplier_id = ? AND employee.id IN (' . $placeholders . ')'
            );
            $stmt->execute([$supplierId, ...$chunk]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $labels[(int) $row['id']] = (string) $row['full_name'];
            }
        }

        return $labels;
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
                           ) AS employment_refs,
                           SUBSTRING_INDEX(
                               GROUP_CONCAT(
                                   REPLACE(code, CHAR(10 USING utf8mb4), ' ')
                                   ORDER BY is_primary DESC, id ASC
                                   SEPARATOR '\n'
                               ),
                               '\n',
                               1
                           ) AS primary_code,
                           MIN(CASE WHEN status NOT IN ('archived', 'no_show')
                                    THEN COALESCE(actual_start_date, start_date) END) AS first_start_on,
                           CASE WHEN SUM(status NOT IN ('archived', 'no_show') AND end_date IS NULL) > 0
                                THEN NULL
                                ELSE MAX(CASE WHEN status NOT IN ('archived', 'no_show') THEN end_date END)
                           END AS last_end_on
                      FROM payroll_employments
                     WHERE supplier_id = ?
                     GROUP BY supplier_id, employee_id
              ) relations
                ON relations.supplier_id = employee.supplier_id
               AND relations.employee_id = employee.id
            SQL;
    }

    /**
     * Pracovní pozice osoby = kód CZ-ISCO z podmínek hlavního vztahu. Bere se
     * verze podmínek platná dnes, u vztahu, který teprve začne, ta nejbližší
     * budoucí. Archivované a nenastoupivší vztahy se nepočítají.
     */
    private static function currentPositionExpression(): string
    {
        return <<<'SQL'
            (
                       SELECT terms.cz_isco_code
                         FROM payroll_employments position_employment
                         JOIN payroll_employment_terms terms
                           ON terms.supplier_id = position_employment.supplier_id
                          AND terms.employment_id = position_employment.id
                        WHERE position_employment.supplier_id = employee.supplier_id
                          AND position_employment.employee_id = employee.id
                          AND position_employment.status NOT IN ('archived', 'no_show')
                          AND terms.cz_isco_code IS NOT NULL
                          AND terms.cz_isco_code <> ''
                        ORDER BY position_employment.is_primary DESC,
                                 position_employment.id ASC,
                                 terms.effective_from > CURRENT_DATE ASC,
                                 terms.effective_from DESC,
                                 terms.id DESC
                        LIMIT 1
                   )
            SQL;
    }

    private function peopleQuery(
        bool $single = false,
        bool $paged = false,
        string $narrowing = '',
        string $orderBy = '',
    ): \PDOStatement {
        $sql = 'SELECT employee.id,
                   ' . self::fullNameExpression() . ' AS full_name,
                   ' . self::currentIdentityExpression('first_name') . ' AS first_name,
                   ' . self::currentIdentityExpression('last_name') . ' AS last_name,
                   COALESCE(' . self::currentIdentityExpression('birth_date') . ', employee.birth_date) AS birth_date,'
            . <<<'SQL'

                   employee.is_active,
                   profile.profile_status,
                   employee.taxpayer_type AS legacy_taxpayer_type,
                   employee.employment_type AS legacy_employment_type,
                   COALESCE(relations.employment_count, 0) AS employment_count,
                   COALESCE(relations.relation_types, '') AS relation_types,
                   COALESCE(relations.employment_refs, '') AS employment_refs,
                   relations.primary_code,
                   relations.first_start_on,
                   relations.last_end_on,
            SQL
            . "\n                   " . self::currentPositionExpression() . ' AS cz_isco_code,'
            . "\n                   " . PayrollPersonDataGapCatalog::selectColumns()
            . ' ' . self::fromClause() . ' WHERE employee.supplier_id = ?';
        if ($single) {
            $sql .= ' AND employee.id = ?';
        }
        $sql .= $narrowing;
        // Řadí se nad odvozenou tabulkou: klíče řazení jsou výrazy nad aliasy
        // SELECTu (příjmení s pádem na celé jméno) a ty uvnitř téhož SELECTu
        // použít nejdou, jen opsat — a opsaný poddotaz by se počítal dvakrát.
        if ($orderBy !== '') {
            $sql = 'SELECT people.* FROM (' . $sql . ') people' . $orderBy;
        }
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
        /*
         * Štítek má JMENOVAT, co chybí — prázdný štítek uživatele jen posílal
         * hádat po celé kartě. Mezery i jejich naléhavost čte KATALOG, ne tenhle
         * repozitář: seznam, karta osoby i kontrola před během tak mluví o téže
         * množině údajů. Starší `setup_gaps` je jeho podmnožina (pětice, kterou
         * vynucuje uložení profilu), takže se obě pole nemůžou rozejít.
         */
        $dataGaps = PayrollPersonDataGapCatalog::fromRow(
            fn (string $column): bool => $this->boolValue($row, $column),
        );
        $legacyKeys = PayrollPersonDataGapCatalog::legacySetupKeys();
        $setupGaps = [];
        foreach ($dataGaps as $gap) {
            if (in_array($gap['key'], $legacyKeys, true)) {
                $setupGaps[] = $gap['key'];
            }
        }

        return [
            'id' => $this->intValue($row, 'id'),
            'full_name' => $this->stringValue($row, 'full_name'),
            'first_name' => $this->nullableStringValue($row, 'first_name'),
            'last_name' => $this->nullableStringValue($row, 'last_name'),
            'birth_date' => $this->nullableStringValue($row, 'birth_date'),
            'start_date' => $this->nullableStringValue($row, 'first_start_on'),
            'end_date' => $this->nullableStringValue($row, 'last_end_on'),
            'cz_isco_code' => $this->nullableStringValue($row, 'cz_isco_code'),
            'is_active' => $this->boolValue($row, 'is_active'),
            'profile_status' => $profileStatus,
            'legacy_taxpayer_type' => $this->stringValue($row, 'legacy_taxpayer_type'),
            'legacy_employment_type' => $this->stringValue($row, 'legacy_employment_type'),
            'employment_count' => $employmentCount,
            'relation_types' => $relationTypes,
            'employment_refs' => $this->employmentRefs($this->stringValue($row, 'employment_refs')),
            'setup_gaps' => $setupGaps,
            'needs_setup' => $setupGaps !== [],
            'data_gaps' => $dataGaps,
            'data_gap_counts' => PayrollPersonDataGapCatalog::counts($dataGaps),
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
    private function nullableStringValue(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return $value === null || $value === '' ? null : $this->stringValue($row, $key);
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
