<?php

declare(strict_types=1);

namespace MyUctoRegression;

final class Tripwire
{
    private static array $violations = [];
    private static array $diagnostics = [];
    private static ?\WeakMap $handles = null;
    private static bool $internal = false;
    private static string $database = '';
    private static ?string $dockerHost = null;

    public static function configure(string $database, ?string $dockerHost): void
    {
        self::$database = $database;
        self::$dockerHost = $dockerHost;
    }

    public static function fail(string $code): never
    {
        self::$violations[$code] = true;
        throw new \RuntimeException($code);
    }

    public static function violations(): array
    {
        return array_keys(self::$violations);
    }

    public static function warning(int $severity, string $file, int $line): never
    {
        self::$diagnostics[] = ['severity' => $severity, 'source' => basename(str_replace('\\', '/', $file)), 'line' => $line];
        self::fail('runtime_warning');
    }

    public static function diagnostics(): array
    {
        return self::$diagnostics;
    }

    public static function databaseError(): void
    {
        self::$violations['database_error'] = true;
    }

    public static function verifyConfig(object $config): void
    {
        if ($config->get('db.name') !== self::$database) {
            self::fail('connection_database_mismatch');
        }
        try {
            Safety::host((string) $config->get('db.host', '127.0.0.1'), self::$dockerHost);
        } catch (\Throwable) {
            self::fail('connection_host_mismatch');
        }
    }

    public static function sql(string $sql): void
    {
        if (self::$internal) {
            return;
        }
        if (preg_match('/\A(?:SAVEPOINT|RELEASE SAVEPOINT|ROLLBACK TO SAVEPOINT) dimension_period_[1-9][0-9]*\z/D', $sql)) {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
            if (($trace[1]['class'] ?? '') === 'MyInvoice\\Infrastructure\\Database\\LoggingPdo'
                && ($trace[1]['function'] ?? '') === 'exec'
                && ($trace[2]['class'] ?? '') === 'MyInvoice\\Service\\Accounting\\Dimension\\DimensionPeriodGuard'
                && ($trace[2]['function'] ?? '') === 'transaction') {
                return;
            }
        }
        // Strings are removed, executable comments are rejected before ordinary comments.
        if (preg_match('~/\*[!+]~', $sql)) {
            self::fail('sql_executable_comment');
        }
        $plain = preg_replace('/\x27(?:\x27\x27|\\\\.|[^\x27])*\x27|"(?:""|\\\\.|[^"])*"/s', "''", $sql);
        $plain = preg_replace('~/\*.*?\*/|--[^\r\n]*|\#[^\r\n]*~s', ' ', (string) $plain);
        $plain = trim((string) $plain);
        if (preg_match('/^SET (?:SESSION sql_mode = \'\'|time_zone = \?)$/iD', $plain)) {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8);
            foreach ($trace as $frame) {
                if (($frame['class'] ?? '') === 'MyInvoice\\Infrastructure\\Database\\Connection' && in_array($frame['function'] ?? '', ['pdo', 'applySessionTimeZone'], true)) {
                    return;
                }
            }
        }
        $nativeFxUnion = false;
        if (substr_count(strtoupper($plain), 'UNION ALL') === 1
            && preg_match('/\A\(SELECT\b.*\)\s+UNION ALL\s+\(SELECT\b.*\)\s+ORDER BY doc_type,\s*doc_id\z/is', $plain)) {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
            $pdoCall = (($trace[1]['class'] ?? '') === 'MyInvoice\\Infrastructure\\Database\\LoggingPdo'
                    && ($trace[1]['function'] ?? '') === 'prepare')
                || (($trace[1]['class'] ?? '') === 'MyInvoice\\Infrastructure\\Database\\LoggingPdoStatement'
                    && ($trace[1]['function'] ?? '') === 'execute');
            $nativeFxUnion = $pdoCall
                && ($trace[2]['class'] ?? '') === 'MyInvoice\\Repository\\ClosingRepository'
                && ($trace[2]['function'] ?? '') === 'openFxItems';
        }
        if ((!preg_match('/^(SELECT|WITH|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $plain) && !$nativeFxUnion)
            || str_contains(rtrim($plain, ';'), ';')
            || preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE|GRANT|REVOKE|CALL|INTO|OUTFILE|DUMPFILE|LOAD_FILE|GET_LOCK|RELEASE_LOCK|SLEEP|BENCHMARK|FOR\s+UPDATE|LOCK\s+IN\s+SHARE)\b/i', $plain)) {
            self::fail('sql_write_or_unsafe_statement');
        }
    }

    public static function activate(\PDO $pdo): void
    {
        self::$handles ??= new \WeakMap();
        self::$internal = true;
        try {
            if (!isset(self::$handles[$pdo])) {
                if ($pdo->inTransaction()) {
                    self::fail('unexpected_existing_transaction');
                }
                $pdo->exec('SET SESSION TRANSACTION READ ONLY');
                $pdo->beginTransaction();
                self::$handles[$pdo] = true;
            }
            $state = $pdo->query('SELECT DATABASE() AS db, @@session.tx_read_only AS ro')->fetch(\PDO::FETCH_ASSOC);
            if ($state['db'] !== self::$database || (int) $state['ro'] !== 1 || !$pdo->inTransaction()) {
                self::fail('readonly_session_mismatch');
            }
        } finally {
            self::$internal = false;
        }
    }

    public static function finish(): void
    {
        foreach (self::$handles ?? [] as $pdo => $_) {
            self::activate($pdo);
            $pdo->rollBack();
        }
        if (self::$violations !== []) {
            throw new \RuntimeException('sticky_violation');
        }
    }
}
