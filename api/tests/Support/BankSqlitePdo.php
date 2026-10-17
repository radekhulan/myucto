<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use PDO;
use PDOStatement;

/**
 * SQL fixture pro bankovní unit testy: MariaDB funkce a locking syntaxe nad SQLite.
 * Zámky jsou pouze lokální atrapa; jejich souběh ověřují integrační testy na MariaDB.
 */
final class BankSqlitePdo extends \Pdo\Sqlite
{
    /** @var array<string,int> */
    private array $locks = [];

    public function __construct()
    {
        parent::__construct('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->createFunction('DATABASE', fn (): string => 'synthetic_bank_unit_' . spl_object_id($this));
        $this->createFunction('GET_LOCK', function (string $name, int $timeout): int {
            $this->locks[$name] = ($this->locks[$name] ?? 0) + 1;
            return 1;
        });
        $this->createFunction('RELEASE_LOCK', function (string $name): ?int {
            if (!isset($this->locks[$name])) return null;
            if (--$this->locks[$name] === 0) unset($this->locks[$name]);
            return 1;
        });
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare((string) preg_replace('/\s+FOR\s+UPDATE\b/i', '', $query), $options);
    }
}
