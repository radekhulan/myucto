<?php

declare(strict_types=1);

namespace MyInvoice\Infrastructure\Database;

use PDO;

/**
 * Triggery, které v cílové databázi neběží kvůli svému vlastníkovi (DEFINER).
 *
 * Trigger se vykonává s právy svého definera. Po přenosu dumpu z jiného serveru
 * nastávají dvě různé situace a obě končí až při zápisu, typicky u výpočtu mezd
 * chybou 500:
 *   - definer na serveru neexistuje (chyba 1449),
 *   - definer existuje, ale k TÉTO databázi nemá práva, protože mu je správce
 *     přidělil jen k původní databázi (chyba 1142 „command denied").
 *
 * Oprava je u obou stejná: znovu vytvořit trigger pod aktuálním účtem
 * (`DEFINER=CURRENT_USER`), se zachovaným tělem i SQL mode. Druhou možností
 * u existujícího účtu je přidělit mu práva (GRANT), to ale dělá správce
 * databáze, ne migrace.
 */
final class TriggerDefinerRepair
{
    public const PROBLEM_MISSING = 'missing';
    public const PROBLEM_NO_PRIVILEGES = 'no_privileges';

    /** Práva, se kterými triggery aplikace čtou a zapisují tabulky. */
    public const REQUIRED_PRIVILEGES = ['SELECT', 'INSERT', 'UPDATE', 'DELETE'];

    public function __construct(private readonly PDO $db) {}

    /**
     * @return array{
     *   database:string,
     *   current_user:string,
     *   triggers:list<array{name:string,definer:string,problem:string}>
     * }
     */
    public function inspect(): array
    {
        $identity = $this->db->query(
            'SELECT DATABASE() AS database_name, CURRENT_USER() AS authenticated_user'
        )->fetch(PDO::FETCH_ASSOC);
        $database = (string) ($identity['database_name'] ?? '');
        $currentUser = (string) ($identity['authenticated_user'] ?? '');
        if ($database === '') {
            throw new \RuntimeException('Není vybraná databáze.');
        }

        $stmt = $this->db->prepare(
            'SELECT TRIGGER_NAME, DEFINER
               FROM information_schema.TRIGGERS
              WHERE TRIGGER_SCHEMA = ?
              ORDER BY TRIGGER_NAME'
        );
        $stmt->execute([$database]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $definers = array_values(array_unique(array_map(
            static fn (array $row): string => (string) $row['DEFINER'],
            $rows,
        )));

        try {
            $problems = [];
            foreach ($definers as $definer) {
                $problem = $this->definerProblem($definer, $database);
                if ($problem !== null) {
                    $problems[$definer] = $problem;
                }
            }
        } catch (\PDOException $e) {
            throw new \RuntimeException(
                'Kontrola definerů vyžaduje oprávnění číst mysql.user a mysql.db: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        $triggers = [];
        foreach ($rows as $row) {
            $definer = (string) $row['DEFINER'];
            if (isset($problems[$definer])) {
                $triggers[] = [
                    'name' => (string) $row['TRIGGER_NAME'],
                    'definer' => $definer,
                    'problem' => $problems[$definer],
                ];
            }
        }

        return ['database' => $database, 'current_user' => $currentUser, 'triggers' => $triggers];
    }

    /**
     * Proč trigger s tímto definerem v databázi neběží, nebo null.
     *
     * Práva se čtou z globálních (`mysql.user`) a databázových (`mysql.db`,
     * včetně vzorů se `%` a `_`) grantů. Práva přes roli nebo jen na
     * jednotlivé tabulky se za dostatečná nepovažují: oprava pod aktuálním
     * účtem je u nich stejně bezpečná a trigger pak nezávisí na cizím účtu.
     */
    public function definerProblem(string $definer, string $database): ?string
    {
        [$user, $host] = self::splitDefiner($definer);
        $global = $this->db->prepare(
            'SELECT Select_priv, Insert_priv, Update_priv, Delete_priv
               FROM mysql.user
              WHERE User = ? AND Host = ?'
        );
        $global->execute([$user, $host]);
        $globalRow = $global->fetch(PDO::FETCH_ASSOC);
        if ($globalRow === false) {
            return self::PROBLEM_MISSING;
        }
        $schema = $this->db->prepare(
            'SELECT Select_priv, Insert_priv, Update_priv, Delete_priv
               FROM mysql.db
              WHERE User = ? AND Host = ? AND ? LIKE Db'
        );
        $schema->execute([$user, $host, $database]);

        return self::hasRequiredPrivileges($globalRow, $schema->fetchAll(PDO::FETCH_ASSOC) ?: [])
            ? null
            : self::PROBLEM_NO_PRIVILEGES;
    }

    /**
     * Stačí práva účtu na triggery aplikace? Každé z {@see self::REQUIRED_PRIVILEGES}
     * musí mít globálně, nebo v některém grantu na databázi.
     *
     * @param array<string,mixed> $global řádek `mysql.user`
     * @param list<array<string,mixed>> $schemaGrants řádky `mysql.db` odpovídající databázi
     */
    public static function hasRequiredPrivileges(array $global, array $schemaGrants): bool
    {
        foreach (self::REQUIRED_PRIVILEGES as $privilege) {
            $column = ucfirst(strtolower($privilege)) . '_priv';
            $granted = strtoupper((string) ($global[$column] ?? 'N')) === 'Y';
            foreach ($schemaGrants as $grant) {
                $granted = $granted || strtoupper((string) ($grant[$column] ?? 'N')) === 'Y';
            }
            if (!$granted) {
                return false;
            }
        }

        return true;
    }

    /** @return array{0:string,1:string} */
    public static function splitDefiner(string $definer): array
    {
        $at = strrpos($definer, '@');
        if ($at === false) {
            return [$definer, '%'];
        }

        return [substr($definer, 0, $at), substr($definer, $at + 1)];
    }

    /** Příkaz GRANT, kterým správce databáze účtu práva přidělí místo opravy triggerů. */
    public static function grantStatement(string $definer, string $database): string
    {
        [$user, $host] = self::splitDefiner($definer);

        return sprintf(
            'GRANT %s ON `%s`.* TO \'%s\'@\'%s\';',
            implode(', ', self::REQUIRED_PRIVILEGES),
            str_replace('`', '``', $database),
            str_replace("'", "''", $user),
            str_replace("'", "''", $host),
        );
    }

    /**
     * Znovu vytvoří triggery pod aktuálním účtem. Tělo i SQL mode zůstávají.
     *
     * @param list<array{name:string,definer:string,problem:string}> $triggers
     */
    public function repair(array $triggers): int
    {
        $quoteIdentifier = static fn (string $name): string => '`' . str_replace('`', '``', $name) . '`';
        $originalMode = (string) $this->db->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        $repaired = 0;
        try {
            foreach ($triggers as $trigger) {
                $name = $trigger['name'];
                $show = $this->db->query('SHOW CREATE TRIGGER ' . $quoteIdentifier($name))->fetch(PDO::FETCH_ASSOC);
                $originalSql = is_array($show) ? (string) ($show['SQL Original Statement'] ?? '') : '';
                $sqlMode = is_array($show) ? (string) ($show['sql_mode'] ?? '') : '';
                if ($originalSql === '') {
                    throw new \RuntimeException("Nelze načíst definici triggeru {$name}.");
                }
                $createSql = preg_replace(
                    '/\ACREATE\s+DEFINER=(?:`(?:``|[^`])+`@`(?:``|[^`])+`|\S+)\s+TRIGGER\s+/i',
                    'CREATE DEFINER=CURRENT_USER TRIGGER ',
                    $originalSql,
                    1,
                    $replacementCount,
                );
                if (!is_string($createSql) || $replacementCount !== 1) {
                    throw new \RuntimeException("Neznámý formát definice triggeru {$name}.");
                }

                $setMode = $this->db->prepare('SET SESSION sql_mode = ?');
                $setMode->execute([$sqlMode]);
                $this->db->exec('DROP TRIGGER IF EXISTS ' . $quoteIdentifier($name));
                try {
                    $this->db->exec($createSql);
                } catch (\Throwable $e) {
                    try {
                        $this->db->exec($originalSql);
                    } catch (\Throwable) {
                    }
                    throw new \RuntimeException("Obnova triggeru {$name} selhala: " . $e->getMessage(), 0, $e);
                }
                $repaired++;
            }
        } finally {
            $restoreMode = $this->db->prepare('SET SESSION sql_mode = ?');
            $restoreMode->execute([$originalMode]);
        }

        return $repaired;
    }
}
