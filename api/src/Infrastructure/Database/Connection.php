<?php

declare(strict_types=1);

namespace MyInvoice\Infrastructure\Database;

use MyInvoice\Infrastructure\Config\Config;
use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class Connection
{
    /**
     * Sdílená PDO spojení TESTOVACÍHO běhu, klíčovaná DSN.
     *
     * Proč to existuje: testy staví DI kontejner per testovací metoda, takže na ~935
     * integračních testů vzniklo 941 nových spojení. Samotný TCP connect na loopback
     * stojí na téhle mašině ~16 ms — a to na JAKÝKOLI port, tedy to není MariaDB, ale
     * síťový stack Windows. Dělalo to ~27 s z ~113 s celé sady. Znovupoužití jednoho
     * socketu tenhle náklad odstraní.
     *
     * Proč NE PDO::ATTR_PERSISTENT: perzistentní spojení si nese session state
     * (rozdělaná transakce, SET time_zone, uživatelské proměnné, named locks) a PDO
     * ho při znovupoužití NEROLLBACKUJE — vzniklo by tiché prosakování stavu mezi
     * testy. Tady se stav uklízí explicitně v resetSharedTestSessions() a rozdělaná
     * transakce se hlásí jako chyba testu (viz Tests\Support\SharedTestConnectionGuard).
     *
     * @var array<string,PDO>
     */
    private static array $sharedPdo = [];

    /**
     * Připnutý `sql_mode` — jedna definice pro navázání spojení i pro reset sdílené
     * testovací session. Kdyby to byly dva literály, můžou se rozejít, což je přesně
     * ta třída chyby, kterou tohle připnutí zavírá.
     */
    private const SQL_MODE = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

    /**
     * Hloubka „nesdílené" zóny — viz withoutSharedTestConnection(). Počítadlo, ne bool,
     * aby šlo zóny vnořovat.
     */
    private static int $isolationDepth = 0;

    /**
     * Sáhl aktuální test na sdílené spojení? Bez toho by úklid session stavu platily
     * i unit testy, které s DB nepracují (2 zbytečné round-tripy na test).
     */
    private static bool $sharedTouched = false;

    /** MariaDB: Unknown or incorrect time zone — server nemá nahrané tzinfo tabulky. */
    private const ERR_UNKNOWN_TIME_ZONE = 1298;

    /**
     * Zná server pojmenované časové zóny (nahrané tzinfo tabulky)? `null` = zatím
     * nezjištěno. Per proces, ne per spojení — odpověď se během běhu nemění.
     */
    private static ?bool $namedTimeZoneSupported = null;

    private ?PDO $pdo = null;
    private bool $usesSharedPdo = false;
    private readonly LoggerInterface $logger;
    /** @var array<string,bool> */
    private array $schemaCache = [];
    private readonly bool $sharingAllowed;

    /** Sdílená (mezi-requestová) cache introspekce schématu — viz sharedSchemaCache(). */
    private ?SchemaCache $sharedSchema = null;
    private bool $sharedSchemaResolved = false;
    private bool $schemaFlushRegistered = false;

    public function __construct(private readonly Config $config, ?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
        // Rozhodnutí padá při KONSTRUKCI, ne až v pdo(): kontejner se staví uvnitř
        // withoutSharedTestConnection(), ale první dotaz přijde až dlouho potom.
        $this->sharingAllowed = self::$isolationDepth === 0;
    }

    /**
     * Připne časovou zónu session na `app.timezone`.
     *
     * Dřív tu stálo `SET time_zone = date('P')`, což mělo dvě vady. Zóna se brala
     * z PHP default zóny v okamžiku připojení, takže spojení otevřené před
     * nastavením zóny (CLI skripty bez kontejneru) skončilo v UTC a v jedné
     * instalaci pak vedle sebe žily DATETIME hodnoty ve dvou zónách. A protože
     * `date('P')` je PEVNÝ offset, renderovala se `TIMESTAMP` pole po přechodu
     * letního času o hodinu jinak než při zápisu — což mimo jiné rozbíjelo
     * ověření hash-chainu auditního logu.
     *
     * Preferuje se proto POJMENOVANÁ zóna, která si přechody řeší sama. Ta ale
     * vyžaduje naimportované tzinfo tabulky (`mariadb-tzinfo-to-sql`); když
     * chybí, MariaDB dotaz odmítne a fallbackem je offset spočtený pro tutéž
     * zónu — pořád deterministický a nezávislý na pořadí volání v PHP.
     */
    private function applySessionTimeZone(PDO $pdo): void
    {
        $timezone = (string) $this->config->get('app.timezone', 'Europe/Prague');
        if ($timezone === '') {
            $timezone = 'Europe/Prague';
        }

        // Zda server pojmenované zóny zná, se zjišťuje JEDNOU za proces přes
        // CONVERT_TZ. Při chybějících tzinfo tabulkách vrátí NULL, ale nevyhodí
        // očekávanou chybu 1298, která by jinak zanesla aplikační log.
        if (self::$namedTimeZoneSupported === null) {
            $probe = $pdo->prepare(
                "SELECT CONVERT_TZ('2000-01-01 00:00:00', '+00:00', ?) IS NOT NULL"
            );
            $probe->execute([$timezone]);
            self::$namedTimeZoneSupported = (bool) $probe->fetchColumn();
        }

        if (self::$namedTimeZoneSupported) {
            try {
                $pdo->prepare('SET time_zone = ?')->execute([$timezone]);

                return;
            } catch (\PDOException $e) {
                // Zapamatovat si „server pojmenované zóny neumí" se smí JEN u chyby,
                // která to skutečně znamená (MariaDB 1298 — Unknown or incorrect time
                // zone). Jinak by jediné výpadkové spojení přepnulo celý proces na
                // offsetový fallback až do konce běhu, a co hůř, skutečná chyba by se
                // schovala za nepravdivé vysvětlení.
                if ((int) ($e->errorInfo[1] ?? 0) !== self::ERR_UNKNOWN_TIME_ZONE) {
                    throw $e;
                }
                self::$namedTimeZoneSupported = false;
            }
        }

        try {
            $offset = (new \DateTimeImmutable('now', new \DateTimeZone($timezone)))->format('P');
        } catch (\Throwable) {
            $offset = date('P');
        }

        $pdo->prepare('SET time_zone = ?')->execute([$offset]);
    }

    public function pdo(): PDO
    {
        if ($this->pdo !== null) {
            self::$sharedTouched = self::$sharedTouched || $this->usesSharedPdo;

            return $this->pdo;
        }

        $host    = $this->config->get('db.host', '127.0.0.1');
        $port    = (int) $this->config->get('db.port', 3306);
        $name    = (string) $this->config->get('db.name');
        $user    = $this->config->get('db.user');
        $pass    = $this->config->get('db.pass', '');
        $charset = $this->config->get('db.charset', 'utf8mb4');

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

        $shareable = $this->sharingAllowed && self::sharedTestConnectionsApply($name);

        if ($shareable && isset(self::$sharedPdo[$dsn])) {
            $this->pdo           = self::$sharedPdo[$dsn];
            $this->usesSharedPdo = true;
            self::$sharedTouched = true;

            return $this->pdo;
        }

        $pdo = new LoggingPdo($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ], $this->logger);

        // sql_mode se připíná EXPLICITNĚ, ať se dev, CI i produkce chovají stejně.
        // Bez toho rozhoduje konfigurace serveru: stroj s vypnutým STRICT_TRANS_TABLES
        // tiše ořízne příliš dlouhou hodnotu nebo zahodí zápis do generovaného sloupce,
        // kdežto výchozí (přísná) MariaDB na tomtéž kódu spadne. Stejný princip jako
        // připnuté konce řádků v .gitattributes — prostředí nesmí měnit chování.
        // Sada odpovídá výchozímu sql_mode MariaDB; ONLY_FULL_GROUP_BY se vědomě
        // nepřidává, není ve výchozím režimu a shodil by existující agregační dotazy.
        $pdo->exec("SET SESSION sql_mode = '" . self::SQL_MODE . "'");
        $this->applySessionTimeZone($pdo);

        if ($shareable) {
            self::$sharedPdo[$dsn] = $pdo;
            $this->usesSharedPdo   = true;
            self::$sharedTouched   = true;
        }

        return $this->pdo = $pdo;
    }

    /**
     * Uvolní PDO spojení (nastaví na null → GC zavře MySQL connection). Web ho
     * nepotřebuje (1 connection per request, zavře se na konci), ale testy stavějí
     * container per metodu — bez uvolnění by se connections kumulovaly přes celý
     * běh a narazily na MariaDB max_connections. Při dalším pdo() se vytvoří znovu.
     *
     * U sdíleného testovacího spojení je to no-op: socket drží celý proces a zavírat
     * ho by znamenalo zahodit přesně tu úsporu, kvůli které sdílení existuje. Metoda
     * ale zůstává funkční (a pro nesdílená spojení nezměněná) — limit max_connections
     * na serveru je 60 a nesdílených spojení vzniká jen hrstka.
     */
    public function close(): void
    {
        $this->schemaCache = [];
        if ($this->usesSharedPdo) {
            return;
        }
        $this->pdo = null;
    }

    /**
     * Spustí továrnu tak, aby Connection vzniklé uvnitř NEDOSTALY sdílené testovací
     * spojení, ale vlastní DB session.
     *
     * Nutné všude, kde test ověřuje chování MEZI dvěma sessions — zámek řádku
     * FOR UPDATE, GET_LOCK, viditelnost necommitnutých dat. Se sdíleným spojením by
     * takový test tvrdil, že izolace funguje, aniž by ji reálně změřil.
     *
     * @template T
     * @param callable():T $factory
     * @return T
     */
    public static function withoutSharedTestConnection(callable $factory): mixed
    {
        ++self::$isolationDepth;
        try {
            return $factory();
        } finally {
            --self::$isolationDepth;
        }
    }

    /**
     * Uklidí session stav sdílených testovacích spojení mezi testy a ohlásí, na kterých
     * zůstala rozdělaná transakce.
     *
     * Dřív měl každý test čerstvé PDO, takže nedokončená transakce zmizela implicitním
     * rollbackem při zavření socketu a nikdo se o ní nedozvěděl. Sdílené spojení ji
     * naopak protáhne do dalšího testu — proto se tady rollbackuje a NAHLAS hlásí.
     * Ze stejného důvodu se vrací i session proměnné a named locks do výchozího stavu:
     * `SET FOREIGN_KEY_CHECKS = 0` nebo GET_LOCK dřív padly se spojením, teď ne.
     *
     * @return list<string> DSN spojení, na kterých byla nalezena rozdělaná transakce
     */
    public static function resetSharedTestSessions(): array
    {
        if (!self::$sharedTouched) {
            return [];
        }
        self::$sharedTouched = false;

        $leaked = [];
        foreach (self::$sharedPdo as $dsn => $pdo) {
            try {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                    $leaked[] = $dsn;
                }
                $pdo->exec(
                    "SET SESSION foreign_key_checks = 1, unique_checks = 1, innodb_lock_wait_timeout = DEFAULT,"
                    . " sql_mode = '" . self::SQL_MODE . "'"
                );
                $pdo->query('SELECT RELEASE_ALL_LOCKS()');
            } catch (\Throwable) {
                // Spojení je rozbité (server odešel, killnutá session) — zahoď ho ze
                // sdílené mapy, další pdo() postaví nové. Tichý catch je tu na místě:
                // jediná alternativa by byla shodit celý běh na infrastrukturní chybě.
                unset(self::$sharedPdo[$dsn]);
            }
        }

        return $leaked;
    }

    /**
     * Sdílení se zapíná jen pod PHPUnit A ZÁROVEŇ proti databázi se jménem končícím
     * na `_test`. Je to táž pojistka, jakou používá tests/bootstrap.php — žádná nová
     * konfigurace, kterou by šlo omylem zapnout v produkci.
     */
    private static function sharedTestConnectionsApply(string $dbName): bool
    {
        return defined('PHPUNIT_COMPOSER_INSTALL') && str_ends_with($dbName, '_test');
    }

    public function hasColumn(string $table, string $column): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            throw new \InvalidArgumentException('Neplatný identifikátor databázového schématu.');
        }
        $key = "column:{$table}.{$column}";
        if (array_key_exists($key, $this->schemaCache)) {
            return $this->schemaCache[$key];
        }
        $shared = $this->sharedSchemaCache()?->get($key);
        if ($shared !== null) {
            return $this->schemaCache[$key] = $shared;
        }

        $pdo = $this->pdo();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $rows = $pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC);
            return $this->schemaCache[$key] = array_any(
                $rows,
                static fn (array $row): bool => (string) ($row['name'] ?? '') === $column,
            );
        }

        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
        );
        $stmt->execute([$table, $column]);

        return $this->rememberSchema($key, $stmt->fetchColumn() !== false);
    }

    public function hasTable(string $table): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new \InvalidArgumentException('Neplatný identifikátor databázového schématu.');
        }
        $key = "table:{$table}";
        if (array_key_exists($key, $this->schemaCache)) {
            return $this->schemaCache[$key];
        }
        $shared = $this->sharedSchemaCache()?->get($key);
        if ($shared !== null) {
            return $this->schemaCache[$key] = $shared;
        }

        $pdo = $this->pdo();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1");
        } else {
            $stmt = $pdo->prepare(
                'SELECT 1 FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
            );
        }
        $stmt->execute([$table]);

        return $this->rememberSchema($key, $stmt->fetchColumn() !== false);
    }

    /**
     * Zapíše výsledek introspekce do obou vrstev cache (request + sdílená).
     */
    private function rememberSchema(string $key, bool $value): bool
    {
        $this->schemaCache[$key] = $value;
        $shared = $this->sharedSchemaCache();
        if ($shared !== null) {
            $shared->put($key, $value);
            // Zápis na disk až na konci requestu — první request po invalidaci
            // objeví několik klíčů za sebou a nemá smysl kvůli každému přepisovat soubor.
            if (!$this->schemaFlushRegistered) {
                $this->schemaFlushRegistered = true;
                register_shutdown_function(static fn () => $shared->flush());
            }
        }

        return $value;
    }

    /**
     * Sdílená cache introspekce, nebo null když je persistence vypnutá.
     *
     * Vypnutá je záměrně:
     *   - pod PHPUnitem — integrační testy vytvářejí a ruší tabulky za běhu, takže
     *     cache přeživší mezi běhy by je rozbila zákeřným způsobem (schéma „existuje",
     *     ale reálně už ne),
     *   - přes `MYINVOICE_SCHEMA_CACHE=0` — únikový východ pro ladění,
     *   - když není kam psát (chybí data dir) nebo neznáme jméno databáze.
     */
    private function sharedSchemaCache(): ?SchemaCache
    {
        if ($this->sharedSchemaResolved) {
            return $this->sharedSchema;
        }
        $this->sharedSchemaResolved = true;

        if (defined('PHPUNIT_COMPOSER_INSTALL')) {
            return $this->sharedSchema = null;
        }
        $flag = getenv('MYINVOICE_SCHEMA_CACHE');
        if ($flag !== false && trim((string) $flag) === '0') {
            return $this->sharedSchema = null;
        }

        $database = (string) $this->config->get('db.name', '');
        $path = SchemaCache::pathFor(
            $this->config->dataDir() ?? \MyInvoice\Bootstrap::rootDir(),
            $database,
        );
        if ($path === null) {
            return $this->sharedSchema = null;
        }

        return $this->sharedSchema = new SchemaCache(
            $path,
            $database,
            // 1 hodina: schéma se v téhle instalaci mění výhradně migracemi a ty
            // cache invalidují explicitně (viz bin/migrate.php). TTL je tu jen
            // jako pojistka pro ruční zásah do schématu mimo migrace — a delší
            // okno navíc znamená, že se cache stihne naplnit klíči napříč všemi
            // endpointy, ne jen těmi z posledních pěti minut.
            (int) $this->config->get('cache.schema_ttl', 3600),
        );
    }

    public function ping(): bool
    {
        try {
            $this->pdo()->query('SELECT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
