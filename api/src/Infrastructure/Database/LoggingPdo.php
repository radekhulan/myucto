<?php

declare(strict_types=1);

namespace MyInvoice\Infrastructure\Database;

use PDO;
use PDOException;
use PDOStatement;
use Psr\Log\LoggerInterface;

/**
 * Transparent PDO subclass — loguje každou PDOException přes Monolog
 * a chybu rethrowne. Aktivuje LoggingPdoStatement přes ATTR_STATEMENT_CLASS,
 * takže `$pdo->prepare(...)->execute(...)` se loguje automaticky bez úprav callerů.
 *
 * Loguje se i prepare() (native prepares posílají statement do MariaDB hned)
 * a one-shot exec()/query().
 */
final class LoggingPdo extends PDO
{
    private string $databaseName;
    private readonly TransactionSavepointTracker $transactions;
    private bool $statementsTracked = true;
    public function __construct(
        string $dsn,
        string $username,
        string $password,
        array $options,
        private readonly LoggerInterface $logger,
        private readonly ?\Closure $schemaCachePathForDatabase = null,
        ?SlowQueryLog $slowLog = null,
    ) {
        parent::__construct($dsn, $username, $password, $options);
        $this->transactions = new TransactionSavepointTracker();
        preg_match('/(?:^|;)dbname=([^;]*)/', substr($dsn, strpos($dsn, ':') + 1), $match);
        $this->databaseName = $match[1] ?? '';
        $this->slowLog = $slowLog?->enabled() ? $slowLog : null;
        $weak = \WeakReference::create($this);
        $schemaChanged = static function (string $sql) use ($weak): void {
            $weak->get()?->noteSchemaStatement($sql);
        };
        $transactionState = static fn (): bool => $weak->get()?->inTransaction() ?? false;
        $statementSucceeded = static function (string $sql, bool $before) use ($weak): void {
            $weak->get()?->noteTransactionStatement($sql, $before);
        };
        $statementFailed = static function (string $sql, bool $before, ?array $errorInfo = null) use ($weak): void {
            $weak->get()?->noteTransactionFailure($sql, $before, $errorInfo);
        };
        parent::setAttribute(PDO::ATTR_STATEMENT_CLASS, [LoggingPdoStatement::class, [$logger, $schemaChanged, $this->slowLog, $this->databaseName, $transactionState, $statementSucceeded, $statementFailed]]);
    }

    private readonly ?SlowQueryLog $slowLog;

    /** @var array<string,\Closure():void> */
    private array $afterCommit = [];

    public function databaseName(): string
    {
        return $this->databaseName;
    }

    public function transactionMarker(string $name): object
    {
        if (!$this->statementsTracked) throw new \LogicException('Transaction statements are not tracked.');
        return $this->transactions->marker($name, parent::inTransaction());
    }

    public function statementsTracked(): bool
    {
        return $this->statementsTracked;
    }

    public function hasTransactionMarker(object $marker): bool
    {
        return $this->statementsTracked && $this->transactions->contains($marker, parent::inTransaction());
    }

    public function beginTransaction(): bool
    {
        $ok = parent::beginTransaction();
        if ($ok) $this->transactions->boundary(parent::inTransaction());
        $this->bumpTableWrites();
        return $ok;
    }

    /**
     * Spustí krok po commitu probíhající transakce, mimo ni hned. Rollback ho zahodí.
     * Týž klíč se v jedné transakci zaregistruje jen jednou.
     * Chyba kroku se nevyhazuje: commit už proběhl a volající ho má hlásit jako úspěch.
     */
    public function afterCommit(string $key, \Closure $callback): void
    {
        if (!$this->inTransaction()) {
            $this->runAfterCommit([$callback]);
            return;
        }
        $this->afterCommit[$key] ??= $callback;
    }

    public function commit(): bool
    {
        $this->bumpTableWrites();
        $result = parent::commit();
        if ($result) $this->transactions->boundary(parent::inTransaction());
        $callbacks = $this->afterCommit;
        $this->afterCommit = [];
        $this->runAfterCommit($callbacks);

        return $result;
    }

    public function rollBack(): bool
    {
        $this->afterCommit = [];
        $this->bumpTableWrites();
        $ok = parent::rollBack();
        if ($ok) $this->transactions->boundary(parent::inTransaction());
        return $ok;
    }

    public function setAttribute(int $attribute, mixed $value): bool
    {
        $before = parent::inTransaction();
        $ok = false;
        try {
            return $ok = parent::setAttribute($attribute, $value);
        } finally {
            if ($ok && $attribute === PDO::ATTR_STATEMENT_CLASS) {
                $this->statementsTracked = false;
                $this->transactions->boundary(parent::inTransaction());
            } elseif ($attribute === PDO::ATTR_AUTOCOMMIT && (!$before || !parent::inTransaction())) {
                $this->transactions->boundary(parent::inTransaction());
            }
            if ($attribute === PDO::ATTR_AUTOCOMMIT) $this->bumpTableWrites();
        }
    }

    /** @param array<array-key,\Closure():void> $callbacks */
    private function runAfterCommit(array $callbacks): void
    {
        foreach ($callbacks as $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                $this->logger->error('Krok po commitu selhal: ' . $e->getMessage(), ['exception' => $e::class]);
            }
        }
    }

    private function noteTransactionFailure(string $sql, bool $before, ?array $errorInfo = null): void
    {
        $this->transactions->failedStatement($sql, $before, parent::inTransaction(), $errorInfo);
        // Deadlock nebo vypršený zámek mohou transakci odvolat: nic přečteného dřív neplatí.
        $this->bumpTableWrites();
    }

    private function noteTransactionStatement(string $sql, bool $before): void
    {
        $this->transactions->statement($sql, $before, parent::inTransaction());
        $this->noteTableWrites($sql);
    }

    private function noteTableWrites(string $sql): void
    {
        if ($this->tableWrites === [] && !$this->writesTracked) {
            return;
        }
        // Hranice transakce, rollback a DDL (implicitní commit) mění všechno.
        if (preg_match('/^\s*(?:ROLLBACK|COMMIT|BEGIN|START|XA|ALTER|DROP|CREATE|RENAME|TRUNCATE|LOCK|UNLOCK|SET\s+(?:SESSION\s+|LOCAL\s+|@@(?:SESSION\.)?)?(?:AUTOCOMMIT|TRANSACTION))\b/i', $sql) === 1) {
            $this->bumpTableWrites();
            return;
        }
        if (preg_match('/^\s*(?:INSERT|REPLACE|UPDATE|DELETE|LOAD|CALL)\b/i', $sql) !== 1) {
            return;
        }
        $this->writes++;
        foreach ($this->tableWrites as $table => $count) {
            if (stripos($sql, $table) !== false) $this->tableWrites[$table] = $count + 1;
        }
    }

    private function bumpTableWrites(): void
    {
        $this->writes++;
        foreach ($this->tableWrites as $table => $count) $this->tableWrites[$table] = $count + 1;
    }

    private int $writes = 0;
    private bool $writesTracked = false;

    /**
     * Čítač všech příkazů, které mohly změnit jakákoli data spojení: každý zápis a každá
     * hranice transakce včetně savepointu a nepovedeného příkazu. Pro paměť výsledku,
     * který se skládá z mnoha tabulek a jejich výčet by se snadno rozešel s kódem.
     */
    public function writeCount(): int
    {
        $this->writesTracked = true;
        return $this->writes;
    }

    /** @var array<string,int> sledovaná tabulka => počet příkazů, které ji mohly změnit */
    private array $tableWrites = [];

    /**
     * Čítač příkazů, které mohly změnit tabulku: zápis, který ji zmiňuje, a každá hranice
     * transakce (begin, commit, rollback i k savepointu, DDL). Slouží k bezpečnému
     * znovupoužití výsledku čteného s `FOR UPDATE` v téže transakci: dokud se čítač
     * nezmění, transakce i data, která drží zamčená, zůstaly stejné. Počítá se až od
     * prvního dotazu na tabulku, proto se porovnává jen se stavem zjištěným dřív.
     */
    public function tableWriteCount(string $table): int
    {
        $table = strtolower($table);
        return $this->tableWrites[$table] ??= 0;
    }

    private function noteSchemaStatement(string $sql): void
    {
        if (SchemaMetadataProvider::isSchemaStatement($sql)) {
            $previous = $this->databaseName;
            $this->databaseName = (string) parent::query('SELECT DATABASE()')->fetchColumn();
            if ($previous !== $this->databaseName) {
                $previousPath = $this->schemaCachePathForDatabase === null ? null : ($this->schemaCachePathForDatabase)($previous);
                SchemaCache::invalidate($previousPath);
            }
            $path = $this->schemaCachePathForDatabase === null ? null : ($this->schemaCachePathForDatabase)($this->databaseName);
            SchemaCache::invalidate($path);
        }
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $before = parent::inTransaction();
        if (array_key_exists(PDO::ATTR_STATEMENT_CLASS, $options)) {
            $this->statementsTracked = false;
            $this->transactions->boundary($before);
        }
        try {
            $result = parent::prepare($query, $options);
            if ($result === false) $this->noteTransactionFailure($query, $before, parent::errorInfo());
            return $result;
        } catch (PDOException $e) {
            $this->noteTransactionFailure($query, $before, $e->errorInfo);
            DbErrorLogger::log($this->logger, $e, $query, []);
            throw $e;
        }
    }

    public function exec(string $statement): int|false
    {
        $before = parent::inTransaction();
        $started = $this->slowLog !== null ? hrtime(true) : 0;
        try {
            $result = parent::exec($statement);
        } catch (PDOException $e) {
            $this->noteTransactionFailure($statement, $before, $e->errorInfo);
            DbErrorLogger::log($this->logger, $e, $statement, []);
            throw $e;
        }
        if ($result !== false) $this->noteTransactionStatement($statement, $before);
        else $this->noteTransactionFailure($statement, $before, parent::errorInfo());
        $this->slowLog?->record($statement, [], $started, $this->databaseName);
        // Až PO úspěšném zápisu — neúspěšný příkaz nemá co invalidovat.
        WriteWatcher::noteStatement($statement);
        $this->noteSchemaStatement($statement);

        return $result;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $before = parent::inTransaction();
        $started = $this->slowLog !== null ? hrtime(true) : 0;
        try {
            $result = parent::query($query, $fetchMode, ...$fetchModeArgs);
        } catch (PDOException $e) {
            $this->noteTransactionFailure($query, $before, $e->errorInfo);
            DbErrorLogger::log($this->logger, $e, $query, []);
            throw $e;
        }
        if ($result !== false) $this->noteTransactionStatement($query, $before);
        else $this->noteTransactionFailure($query, $before, parent::errorInfo());
        $this->slowLog?->record($query, [], $started, $this->databaseName);
        WriteWatcher::noteStatement($query);
        $this->noteSchemaStatement($query);

        return $result;
    }
}
