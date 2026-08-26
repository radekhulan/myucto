<?php

declare(strict_types=1);

namespace MyInvoice\Service\System;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Cache\RedisProbe;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Cron\CronCatalog;
use MyInvoice\Service\Cron\CronDispatcher;
use MyInvoice\Service\Cron\CronHealth;
use MyInvoice\Service\Cron\CronJobGate;
use MyInvoice\Service\Cron\CronScheduleMode;
use MyInvoice\Service\Update\VersionService;
use PDO;

/**
 * Audit prostředí self-hosted instalace — verdikt, ne výpis hodnot.
 *
 * Každá kontrola vrací `id`, strojový `status` (ok/warn/fail/skip) a naměřenou
 * vs. očekávanou hodnotu. Popisky, dopad a návod na nápravu si dohledá frontend
 * podle `id` (i18n), aby texty existovaly česky i anglicky na jednom místě.
 *
 * Kontroly jsou zásadně READ-ONLY a nesmí nikdy vyhodit výjimku: diagnostika,
 * která spadne na tom, co má diagnostikovat, je k ničemu. Každý dílčí sběr je
 * proto obalený a při selhání degraduje na `skip`.
 */
final class EnvironmentCheckService
{
    public const STATUS_OK   = 'ok';
    public const STATUS_WARN = 'warn';
    public const STATUS_FAIL = 'fail';
    public const STATUS_SKIP = 'skip';

    /**
     * Rozšíření, bez kterých aplikace nefunguje. Odpovídá `require` v
     * `api/composer.json` — držet v synchronizaci.
     */
    public const REQUIRED_EXTENSIONS = [
        'bcmath', 'ctype', 'dom', 'fileinfo', 'filter', 'gd', 'iconv', 'json',
        'libxml', 'mbstring', 'openssl', 'pdo', 'pdo_mysql', 'SimpleXML',
        'xmlreader', 'zip', 'zlib',
    ];

    /**
     * Rozšíření s funkčním fallbackem — jejich absence aplikaci nezastaví,
     * jen ji zpomalí nebo omezí jednu cestu. Hodnota = klíč i18n důsledku.
     */
    public const OPTIONAL_EXTENSIONS = [
        'sodium'  => 'license',
        'curl'    => 'http',
        'soap'    => 'vies',
        'imagick' => 'images',
        'exif'    => 'photos',
        'intl'    => 'formatting',
    ];

    /**
     * Kontroly, které dávají smysl ještě PŘED prvním setupem — tedy takové, na
     * které má člověk vliv při instalaci prostředí. Cron sem nepatří (na čerstvé
     * instalaci ještě nic neběželo), stejně jako stav vydání nebo velikost logů.
     * Výjimkou z provozní konfigurace je app.url: chybějící doplní setup, ale
     * explicitně chybnou hodnotu nepřepíše, a proto ji musí ukázat už preflight.
     *
     * @var list<string>
     */
    public const PREFLIGHT_CHECKS = [
        'php_version', 'php_extensions', 'php_extensions_optional',
        'memory_limit', 'upload_limits', 'date_timezone', 'timezone_alignment', 'opcache', 'app_url',
        'db_version', 'db_charset', 'db_max_allowed_packet', 'db_sql_mode',
        'redis', 'disk_space', 'writable_paths', 'migrations_pending',
    ];

    private const MIN_PHP           = '8.5.0';
    private const MIN_MARIADB       = '11.8';
    private const MIN_MEMORY_BYTES  = 256 * 1024 * 1024;
    private const MIN_FREE_BYTES    = 2 * 1024 * 1024 * 1024;
    private const CRIT_FREE_BYTES   = 512 * 1024 * 1024;
    private const LOG_WARN_BYTES    = 1024 * 1024 * 1024;
    private const CRON_MAX_AGE_SEC  = 26 * 3600;

    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
        private readonly RedisProbe $redis,
        private readonly VersionService $version,
        private readonly AppUrlConfiguration $appUrl,
    ) {}

    /**
     * Kompletní report: naměřená fakta + vyhodnocené kontroly + souhrn.
     *
     * @param list<string>|null $onlyIds Omezení na vybrané kontroly; null = všechny.
     * @return array{
     *     generated_at:string,
     *     summary:array{status:string,ok:int,warn:int,fail:int,skip:int},
     *     checks:list<array<string,mixed>>,
     *     facts:array<string,mixed>
     * }
     */
    public function report(?array $onlyIds = null): array
    {
        return $this->buildReport($onlyIds, false);
    }

    /**
     * @param list<string>|null $onlyIds
     * @return array{
     *     generated_at:string,
     *     summary:array{status:string,ok:int,warn:int,fail:int,skip:int},
     *     checks:list<array<string,mixed>>,
     *     facts:array<string,mixed>
     * }
     */
    private function buildReport(?array $onlyIds, bool $isSetupPreflight): array
    {
        $facts  = $this->facts();
        $checks = $this->evaluate($facts, $onlyIds, $isSetupPreflight);

        $counts = [self::STATUS_OK => 0, self::STATUS_WARN => 0, self::STATUS_FAIL => 0, self::STATUS_SKIP => 0];
        foreach ($checks as $check) {
            $counts[$check['status']] = ($counts[$check['status']] ?? 0) + 1;
        }

        $overall = $counts[self::STATUS_FAIL] > 0
            ? self::STATUS_FAIL
            : ($counts[self::STATUS_WARN] > 0 ? self::STATUS_WARN : self::STATUS_OK);

        return [
            'generated_at' => date(\DateTimeInterface::ATOM),
            'summary'      => [
                'status' => $overall,
                'ok'     => $counts[self::STATUS_OK],
                'warn'   => $counts[self::STATUS_WARN],
                'fail'   => $counts[self::STATUS_FAIL],
                'skip'   => $counts[self::STATUS_SKIP],
            ],
            'checks' => $checks,
            'facts'  => $facts,
        ];
    }

    /**
     * Preflight před prvním setupem — jen kontroly z {@see PREFLIGHT_CHECKS} a
     * bez naměřených faktů: tenhle report čte i nepřihlášený návštěvník čerstvé
     * instalace, takže ven jde verdikt a nic víc.
     *
     * @return array{
     *     generated_at:string,
     *     environment:string,
     *     summary:array{status:string,ok:int,warn:int,fail:int,skip:int},
     *     checks:list<array<string,mixed>>
     * }
     */
    public function preflight(): array
    {
        $report = $this->buildReport(self::PREFLIGHT_CHECKS, true);
        unset($report['facts']);

        $environment = $this->guard(fn () => $this->version->detectEnvironment(), 'native');

        // V kontejneru se PHP ani MariaDB neladí přes php.ini na hostiteli —
        // odkaz do manuálu proto míří na kapitolu o Dockeru.
        if ($environment === 'docker') {
            foreach ($report['checks'] as $i => $check) {
                if ($check['manual'] === '04_Instalace_Nativni') {
                    $report['checks'][$i]['manual'] = '03_Instalace_Docker';
                }
            }
        }

        return ['environment' => $environment] + $report;
    }

    // ── Sběr faktů ───────────────────────────────────────────────────────────

    /**
     * Naměřená fakta o prostředí. Konfigurace ani tajemství sem nepatří — od
     * toho je {@see DiagnosticsConfigAllowlist}.
     *
     * @return array<string,mixed>
     */
    public function facts(): array
    {
        return [
            'system'   => $this->guard(fn () => $this->systemFacts(), []),
            'php'      => $this->guard(fn () => $this->phpFacts(), []),
            'database' => $this->guard(fn () => $this->databaseFacts(), ['available' => false]),
            'redis'    => $this->guard(fn () => $this->redisFacts(), ['enabled' => false]),
            'storage'  => $this->guard(fn () => $this->storageFacts(), []),
            'runtime'  => $this->guard(fn () => $this->runtimeFacts(), []),
        ];
    }

    /** @return array<string,mixed> */
    private function systemFacts(): array
    {
        $tz = date_default_timezone_get();

        return [
            'os'              => PHP_OS_FAMILY,
            'uname'           => php_uname('s') . ' ' . php_uname('r'),
            'architecture'    => PHP_INT_SIZE === 8 ? 'x64' : 'x86',
            'hostname'        => (string) (gethostname() ?: ''),
            'server_software' => (string) ($_SERVER['SERVER_SOFTWARE'] ?? ''),
            'sapi'            => PHP_SAPI,
            'environment'     => $this->version->detectEnvironment(),
            'system_timezone' => $tz,
            'server_time'     => date(\DateTimeInterface::ATOM),
            'utc_offset'      => date('P'),
        ];
    }

    /** @return array<string,mixed> */
    private function phpFacts(): array
    {
        $loaded  = array_map('strtolower', get_loaded_extensions());
        $missing = [];
        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            if (!in_array(strtolower($ext), $loaded, true)) {
                $missing[] = $ext;
            }
        }
        $missingOptional = [];
        foreach (array_keys(self::OPTIONAL_EXTENSIONS) as $ext) {
            if (!in_array(strtolower($ext), $loaded, true)) {
                $missingOptional[] = $ext;
            }
        }

        $opcache = [];
        if (function_exists('opcache_get_status')) {
            $status = @opcache_get_status(false);
            if (is_array($status)) {
                $opcache = [
                    'enabled'         => (bool) ($status['opcache_enabled'] ?? false),
                    'used_memory'     => (int) ($status['memory_usage']['used_memory'] ?? 0),
                    'free_memory'     => (int) ($status['memory_usage']['free_memory'] ?? 0),
                    'cached_scripts'  => (int) ($status['opcache_statistics']['num_cached_scripts'] ?? 0),
                    'max_cached_keys' => (int) ($status['opcache_statistics']['max_cached_keys'] ?? 0),
                ];
            }
        }

        return [
            'version'          => PHP_VERSION,
            'extensions'       => $loaded,
            'missing_required' => $missing,
            'missing_optional' => $missingOptional,
            'sodium_fallback'  => !extension_loaded('sodium') && class_exists(\ParagonIE_Sodium_Compat::class),
            'ini'              => [
                'memory_limit'        => (string) ini_get('memory_limit'),
                'max_execution_time'  => (string) ini_get('max_execution_time'),
                'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
                'post_max_size'       => (string) ini_get('post_max_size'),
                'max_input_vars'      => (string) ini_get('max_input_vars'),
                'max_file_uploads'    => (string) ini_get('max_file_uploads'),
                'date.timezone'       => (string) ini_get('date.timezone'),
                'allow_url_fopen'     => (string) ini_get('allow_url_fopen'),
                'disable_functions'   => (string) ini_get('disable_functions'),
                'opcache.enable'      => (string) ini_get('opcache.enable'),
                'opcache.enable_cli'  => (string) ini_get('opcache.enable_cli'),
                'opcache.memory_consumption'   => (string) ini_get('opcache.memory_consumption'),
                'opcache.validate_timestamps'  => (string) ini_get('opcache.validate_timestamps'),
                'opcache.max_accelerated_files' => (string) ini_get('opcache.max_accelerated_files'),
            ],
            'opcache' => $opcache,
        ];
    }

    /** @return array<string,mixed> */
    private function databaseFacts(): array
    {
        $pdo = $this->db->pdo();

        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $isMaria = stripos($version, 'mariadb') !== false;

        $wanted = [
            'sql_mode', 'max_allowed_packet', 'innodb_buffer_pool_size',
            'innodb_file_per_table', 'innodb_flush_log_at_trx_commit',
            'character_set_server', 'collation_server', 'wait_timeout',
            'max_connections', 'time_zone', 'log_bin', 'version_comment',
        ];
        // `SHOW VARIABLES` se záměrně čte celé a filtruje v PHP: prepared statement
        // s IN(...) tu není spolehlivý napříč verzemi a jde o jednotky kB dat.
        $variables = [];
        $stmt = $pdo->query('SHOW VARIABLES');
        if ($stmt !== false) {
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $name = (string) ($row['Variable_name'] ?? '');
                if (in_array($name, $wanted, true)) {
                    $variables[$name] = (string) ($row['Value'] ?? '');
                }
            }
        }

        $sessionSqlMode = (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();

        $schema = null;
        try {
            $row = $pdo->query(
                'SELECT DEFAULT_CHARACTER_SET_NAME AS charset, DEFAULT_COLLATION_NAME AS collation
                   FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()'
            )->fetch(PDO::FETCH_ASSOC);
            $schema = is_array($row) ? $row : null;
        } catch (\Throwable) {
            $schema = null;
        }

        $size = null;
        try {
            $size = (int) $pdo->query(
                'SELECT COALESCE(SUM(data_length + index_length), 0)
                   FROM information_schema.TABLES WHERE table_schema = DATABASE()'
            )->fetchColumn();
        } catch (\Throwable) {
            $size = null;
        }

        return [
            'available'        => true,
            'server'           => $isMaria ? 'MariaDB' : 'MySQL',
            'version'          => $version,
            'version_number'   => self::numericVersion($version),
            'variables'        => $variables,
            'session_sql_mode' => $sessionSqlMode,
            'schema'           => $schema,
            'size_bytes'       => $size,
        ];
    }

    /** @return array<string,mixed> */
    private function redisFacts(): array
    {
        $enabled = (bool) $this->config->get('redis.enabled', false);

        return [
            'enabled'   => $enabled,
            'available' => $enabled && $this->redis->isAvailable(),
        ];
    }

    /** @return array<string,mixed> */
    private function storageFacts(): array
    {
        $storage = RuntimePaths::storage();
        $log     = RuntimePaths::log();

        $free  = @disk_free_space($storage);
        $total = @disk_total_space($storage);

        $writable = [];
        foreach (['storage' => $storage, 'log' => $log, 'cache' => RuntimePaths::storage('cache'), 'tmp' => RuntimePaths::storage('tmp')] as $name => $dir) {
            $writable[$name] = is_dir($dir) ? is_writable($dir) : null;
        }

        return [
            'data_dir'        => Config::resolveDataDir(),
            'root_dir'        => Bootstrap::rootDir(),
            'free_bytes'      => is_float($free) ? (int) $free : null,
            'total_bytes'     => is_float($total) ? (int) $total : null,
            'log_bytes'       => self::dirSize($log),
            'writable'        => $writable,
        ];
    }

    /** @return array<string,mixed> */
    private function runtimeFacts(): array
    {
        return [
            'app_env'       => (string) $this->config->get('app.env', 'production'),
            'app_debug'     => (bool) $this->config->get('app.debug', false),
            'app_timezone'  => (string) $this->config->get('app.timezone', 'Europe/Prague'),
            'logging_level' => (string) $this->config->get('logging.level', 'info'),
            'migrations'    => $this->migrationStatus(),
            'cron'          => $this->cronStatus(),
        ];
    }

    /**
     * Stav migrací — read-only ekvivalent `php api/bin/migrate.php --status`.
     * Nikdy nic nevytváří: chybějící tabulka `migrations` je sama o sobě nález.
     *
     * @return array<string,mixed>
     */
    public function migrationStatus(): array
    {
        if (!$this->db->hasTable('migrations')) {
            return ['available' => false, 'applied' => 0, 'pending' => [], 'pending_count' => null];
        }

        try {
            $applied = $this->db->pdo()->query('SELECT filename FROM migrations')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (\Throwable) {
            return ['available' => false, 'applied' => 0, 'pending' => [], 'pending_count' => null];
        }
        $appliedMap = array_flip(array_map('strval', $applied));

        $files = glob(Bootstrap::rootDir() . '/db/migrations/*.sql') ?: [];
        sort($files, SORT_STRING);

        $pending = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (!isset($appliedMap[$name])) {
                $pending[] = $name;
            }
        }

        return [
            'available'     => true,
            'applied'       => count($appliedMap),
            'total'         => count($files),
            'pending'       => $pending,
            'pending_count' => count($pending),
        ];
    }

    /**
     * Stav plánovaných úloh. Detailní rozpad má `/api/admin/cron-jobs`; tady jde
     * jen o to, jestli něco relevantního přestalo běžet.
     *
     * Relevanci ani zdraví tahle třída nedefinuje — ptá se {@see CronJobGate}
     * a {@see CronHealth}, tedy přesně těch zdrojů, ze kterých čte i stránka
     * Plánované úlohy. Druhá definice by se s tou první nutně rozešla.
     *
     * @return array<string,mixed>
     */
    private function cronStatus(): array
    {
        $unavailable = [
            'available'         => false,
            'oldest_ok_age_sec' => null,
            'jobs'              => 0,
            'stale'             => [],
            'inactive'          => [],
            'idle'              => [],
        ];

        if (!$this->db->hasTable('cron_heartbeat')) {
            return $unavailable;
        }

        try {
            $pdo  = $this->db->pdo();
            $rows = $pdo->query(
                'SELECT script, last_ok_at, last_tick_at, last_status FROM cron_heartbeat'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            return $unavailable;
        }

        $now  = time();
        $mode = CronScheduleMode::current($pdo);
        $gate = new CronJobGate($this->config, $pdo);

        $inactive = [];
        foreach (CronCatalog::all() as $job) {
            $reason = $this->guard(fn () => $gate->inactiveReason($job, $mode), null);
            if ($reason !== null) {
                $inactive[(string) $job['script']] = $reason;
            }
        }

        // V režimu dispatcheru je jeho vlastní heartbeat důkazem, že se plánuje —
        // a tím pádem že ticho gatované úlohy znamená „není práce", ne výpadek.
        $dispatcherAlive = false;
        $gatedScripts    = [];
        if ($mode === CronScheduleMode::DISPATCHER) {
            $heartbeats = [];
            foreach ($rows as $row) {
                $heartbeats[(string) $row['script']] = $row;
            }
            $dispatcherAlive = CronHealth::isDispatcherAlive(
                $heartbeats[CronCatalog::DISPATCHER_SCRIPT] ?? null,
                CronCatalog::maxAgeHours(CronCatalog::DISPATCHER_SCRIPT) * 3600,
                $now,
            );
            $gatedScripts = CronDispatcher::gatedScripts();
        }

        return ['available' => true, 'jobs' => count($rows), 'inactive' => $inactive]
            + self::classifyCronHeartbeats($rows, $inactive, $gatedScripts, $dispatcherAlive, $now);
    }

    /**
     * Roztřídí heartbeaty na zaseklé, nečinné a v pořádku.
     *
     * Vytaženo z {@see cronStatus()}, aby šlo otestovat bez databáze — právě
     * tady se rozhoduje, co uživatel uvidí červeně.
     *
     * @param list<array<string,mixed>> $rows řádky `cron_heartbeat`
     * @param array<string,string> $inactive skript => důvod nečinnosti
     * @param list<string> $gatedScripts skripty, které dispatcher spouští jen když mají práci
     * @return array{oldest_ok_age_sec:?int,stale:list<string>,idle:list<string>}
     */
    public static function classifyCronHeartbeats(
        array $rows,
        array $inactive,
        array $gatedScripts,
        bool $dispatcherAlive,
        int $now,
    ): array {
        $catalog = [];
        foreach (CronCatalog::all() as $job) {
            $catalog[(string) $job['script']] = $job;
        }

        $oldest = null;
        $stale  = [];
        $idle   = [];

        foreach ($rows as $row) {
            $script = (string) ($row['script'] ?? '');
            if ($script === '' || isset($inactive[$script])) {
                continue;
            }

            $lastOk = $row['last_ok_at'] ?? $row['last_tick_at'] ?? null;
            if ($lastOk === null) {
                continue;
            }
            $age = $now - (int) strtotime((string) $lastOk);
            if ($oldest === null || $age > $oldest) {
                $oldest = $age;
            }

            $job = $catalog[$script] ?? null;
            if ($job === null) {
                // Skript mimo katalog (pozůstatek po odstraněné úloze, ruční zápis):
                // nevíme, jak často má běžet, takže platí původní plochý limit.
                if ($age > self::CRON_MAX_AGE_SEC) {
                    $stale[] = $script;
                }
                continue;
            }

            [$health] = CronHealth::evaluate(
                $age,
                isset($row['last_status']) ? (string) $row['last_status'] : null,
                (int) $job['max_age_hours'] * 3600,
                in_array($script, $gatedScripts, true),
                $dispatcherAlive,
            );

            if ($health === CronHealth::IDLE) {
                $idle[] = $script;
            } elseif ($health === CronHealth::OVERDUE || $health === CronHealth::OVERDUE_AND_FAILING) {
                $stale[] = $script;
            }
        }

        return ['oldest_ok_age_sec' => $oldest, 'stale' => $stale, 'idle' => $idle];
    }

    // ── Vyhodnocení pravidel ─────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $facts
     * @param list<string>|null $onlyIds
     * @return list<array<string,mixed>>
     */
    private function evaluate(
        array $facts,
        ?array $onlyIds = null,
        bool $isSetupPreflight = false,
    ): array
    {
        $php     = $facts['php'] ?? [];
        $ini     = $php['ini'] ?? [];
        $db      = $facts['database'] ?? [];
        $vars    = $db['variables'] ?? [];
        $store   = $facts['storage'] ?? [];
        $system  = $facts['system'] ?? [];
        $runtime = $facts['runtime'] ?? [];

        $checks = [];

        // --- PHP ---
        $checks[] = $this->check(
            'php_version',
            version_compare((string) ($php['version'] ?? '0'), self::MIN_PHP, '>=') ? self::STATUS_OK : self::STATUS_FAIL,
            (string) ($php['version'] ?? '?'),
            '>= ' . self::MIN_PHP,
            '04_Instalace_Nativni'
        );

        $missing = $php['missing_required'] ?? [];
        $checks[] = $this->check(
            'php_extensions',
            $missing === [] ? self::STATUS_OK : self::STATUS_FAIL,
            $missing === [] ? '' : implode(', ', $missing),
            implode(', ', self::REQUIRED_EXTENSIONS),
            '04_Instalace_Nativni',
            ['missing' => array_values($missing)]
        );

        $missingOpt = $php['missing_optional'] ?? [];
        $checks[] = $this->check(
            'php_extensions_optional',
            $missingOpt === [] ? self::STATUS_OK : self::STATUS_WARN,
            $missingOpt === [] ? '' : implode(', ', $missingOpt),
            implode(', ', array_keys(self::OPTIONAL_EXTENSIONS)),
            '04_Instalace_Nativni',
            [
                'missing'          => array_values($missingOpt),
                'sodium_fallback'  => (bool) ($php['sodium_fallback'] ?? false),
            ]
        );

        $memory = self::iniBytes((string) ($ini['memory_limit'] ?? ''));
        $checks[] = $this->check(
            'memory_limit',
            $memory === -1 || $memory >= self::MIN_MEMORY_BYTES ? self::STATUS_OK : self::STATUS_WARN,
            (string) ($ini['memory_limit'] ?? '?'),
            '>= 256M',
            '04_Instalace_Nativni'
        );

        $upload = self::iniBytes((string) ($ini['upload_max_filesize'] ?? ''));
        $post   = self::iniBytes((string) ($ini['post_max_size'] ?? ''));
        $docMax = (int) $this->config->get('documents.max_file_bytes', 50 * 1024 * 1024);
        $uploadStatus = self::STATUS_OK;
        if ($upload > 0 && $post > 0 && $post < $upload) {
            $uploadStatus = self::STATUS_FAIL;
        } elseif ($upload > 0 && $upload < $docMax) {
            $uploadStatus = self::STATUS_WARN;
        }
        $checks[] = $this->check(
            'upload_limits',
            $uploadStatus,
            'upload_max_filesize=' . ($ini['upload_max_filesize'] ?? '?') . ', post_max_size=' . ($ini['post_max_size'] ?? '?'),
            'post_max_size >= upload_max_filesize >= ' . self::humanBytes($docMax),
            '31_Dokumenty',
            ['upload_bytes' => $upload, 'post_bytes' => $post, 'documents_max_bytes' => $docMax]
        );

        $tzIni = trim((string) ($ini['date.timezone'] ?? ''));
        $checks[] = $this->check(
            'date_timezone',
            $tzIni !== '' ? self::STATUS_OK : self::STATUS_WARN,
            $tzIni !== '' ? $tzIni : '(prázdné)',
            (string) ($runtime['app_timezone'] ?? 'Europe/Prague'),
            '04_Instalace_Nativni'
        );

        $dbTz    = (string) ($vars['time_zone'] ?? '');
        $appTz   = (string) ($runtime['app_timezone'] ?? '');
        $sysTz   = (string) ($system['system_timezone'] ?? '');
        // Aplikace si `time_zone` připíná per session z `app.timezone`, takže
        // rozdíl na serveru je informace, ne chyba — hlásíme jako varování.
        $tzAligned = $sysTz !== '' && $appTz !== '' && $sysTz === $appTz;
        $checks[] = $this->check(
            'timezone_alignment',
            $tzAligned ? self::STATUS_OK : self::STATUS_WARN,
            'PHP=' . $sysTz . ', app=' . $appTz . ', DB=' . ($dbTz !== '' ? $dbTz : '?'),
            'PHP = app',
            '04_Instalace_Nativni'
        );

        $opcacheOn = (string) ($ini['opcache.enable'] ?? '0') === '1';
        $checks[] = $this->check(
            'opcache',
            $opcacheOn ? self::STATUS_OK : self::STATUS_WARN,
            $opcacheOn ? 'zapnuto' : 'vypnuto',
            'zapnuto',
            '04_Instalace_Nativni'
        );

        $validateTs = (string) ($ini['opcache.validate_timestamps'] ?? '1');
        $checks[] = $this->check(
            'opcache_validate_timestamps',
            !$opcacheOn || $validateTs === '1' ? self::STATUS_OK : self::STATUS_WARN,
            $validateTs === '1' ? 'zapnuto' : 'vypnuto',
            'zapnuto, nebo restart PHP po každé aktualizaci',
            '98_Aktualizace'
        );

        // --- Databáze ---
        if (empty($db['available'])) {
            $checks[] = $this->check('db_version', self::STATUS_FAIL, 'nedostupná', 'MariaDB >= ' . self::MIN_MARIADB, '04_Instalace_Nativni');
        } else {
            $isMaria = ($db['server'] ?? '') === 'MariaDB';
            $num     = (string) ($db['version_number'] ?? '0');
            $dbStatus = self::STATUS_OK;
            if (!$isMaria) {
                $dbStatus = self::STATUS_FAIL;
            } elseif (version_compare($num, self::MIN_MARIADB, '<')) {
                $dbStatus = self::STATUS_FAIL;
            }
            $checks[] = $this->check(
                'db_version',
                $dbStatus,
                (string) ($db['server'] ?? '?') . ' ' . $num,
                'MariaDB >= ' . self::MIN_MARIADB,
                '04_Instalace_Nativni'
            );

            $collation = (string) ($db['schema']['collation'] ?? '');
            $charset   = (string) ($db['schema']['charset'] ?? '');
            $checks[] = $this->check(
                'db_charset',
                str_starts_with($charset, 'utf8mb4') ? self::STATUS_OK : self::STATUS_FAIL,
                $charset . ($collation !== '' ? ' / ' . $collation : ''),
                'utf8mb4',
                '04_Instalace_Nativni'
            );

            $packet = (int) ($vars['max_allowed_packet'] ?? 0);
            $needed = max($docMax, 16 * 1024 * 1024) + (4 * 1024 * 1024);
            $checks[] = $this->check(
                'db_max_allowed_packet',
                $packet === 0 ? self::STATUS_SKIP : ($packet >= $needed ? self::STATUS_OK : self::STATUS_WARN),
                $packet > 0 ? self::humanBytes($packet) : '?',
                '>= ' . self::humanBytes($needed),
                '04_Instalace_Nativni'
            );

            // Aplikace si sql_mode připíná per session (Connection::SQL_MODE),
            // takže globální hodnota ovlivňuje jen ruční zásahy a mariadb-dump.
            $session = (string) ($db['session_sql_mode'] ?? '');
            $checks[] = $this->check(
                'db_sql_mode',
                str_contains($session, 'STRICT_TRANS_TABLES') ? self::STATUS_OK : self::STATUS_WARN,
                $session !== '' ? $session : '?',
                'STRICT_TRANS_TABLES',
                '04_Instalace_Nativni',
                ['global' => (string) ($vars['sql_mode'] ?? '')]
            );
        }

        // --- Redis ---
        $redisEnabled = (bool) ($facts['redis']['enabled'] ?? false);
        $redisUp      = (bool) ($facts['redis']['available'] ?? false);
        $checks[] = $this->check(
            'redis',
            !$redisEnabled ? self::STATUS_SKIP : ($redisUp ? self::STATUS_OK : self::STATUS_FAIL),
            !$redisEnabled ? 'vypnutý' : ($redisUp ? 'dostupný' : 'nedostupný'),
            'dostupný, je-li zapnutý',
            '03_Instalace_Docker'
        );

        // --- Úložiště ---
        $free = $store['free_bytes'] ?? null;
        $freeStatus = self::STATUS_SKIP;
        if (is_int($free)) {
            $freeStatus = $free < self::CRIT_FREE_BYTES
                ? self::STATUS_FAIL
                : ($free < self::MIN_FREE_BYTES ? self::STATUS_WARN : self::STATUS_OK);
        }
        $checks[] = $this->check(
            'disk_space',
            $freeStatus,
            is_int($free) ? self::humanBytes($free) : '?',
            '>= ' . self::humanBytes(self::MIN_FREE_BYTES),
            '999_Reseni_problemu'
        );

        $logBytes = $store['log_bytes'] ?? null;
        $checks[] = $this->check(
            'log_size',
            !is_int($logBytes) ? self::STATUS_SKIP : ($logBytes > self::LOG_WARN_BYTES ? self::STATUS_WARN : self::STATUS_OK),
            is_int($logBytes) ? self::humanBytes($logBytes) : '?',
            '< ' . self::humanBytes(self::LOG_WARN_BYTES),
            '999_Reseni_problemu'
        );

        $notWritable = [];
        foreach (($store['writable'] ?? []) as $name => $ok) {
            if ($ok === false) {
                $notWritable[] = $name;
            }
        }
        $checks[] = $this->check(
            'writable_paths',
            $notWritable === [] ? self::STATUS_OK : self::STATUS_FAIL,
            $notWritable === [] ? '' : implode(', ', $notWritable),
            'storage, log, cache, tmp',
            '04_Instalace_Nativni',
            ['not_writable' => $notWritable]
        );

        // --- Migrace, cron ---
        $mig = $runtime['migrations'] ?? [];
        $pendingCount = $mig['pending_count'] ?? null;
        $checks[] = $this->check(
            'migrations_pending',
            $pendingCount === null ? self::STATUS_SKIP : ($pendingCount > 0 ? self::STATUS_FAIL : self::STATUS_OK),
            $pendingCount === null ? '?' : (string) $pendingCount,
            '0',
            '98_Aktualizace',
            ['pending' => array_slice((array) ($mig['pending'] ?? []), 0, 20)]
        );

        $cron     = $runtime['cron'] ?? [];
        $stale    = (array) ($cron['stale'] ?? []);
        $inactive = (array) ($cron['inactive'] ?? []);
        $idle     = (array) ($cron['idle'] ?? []);
        $checks[] = $this->check(
            'cron_health',
            empty($cron['available']) ? self::STATUS_SKIP : ($stale === [] ? self::STATUS_OK : self::STATUS_FAIL),
            $stale === [] ? '' : implode(', ', array_slice($stale, 0, 8)),
            // Ne plochých 26 hodin: měsíční úloha se za dvanáct dní nezasekla.
            // Interval si nese každá úloha v katalogu sama.
            'každá aktivní úloha proběhla ve svém intervalu',
            '97_Bezpecnost',
            ['stale' => array_values($stale), 'inactive' => $inactive, 'idle' => array_values($idle)],
            implode(', ', array_slice(array_keys($inactive), 0, 8))
        );

        // --- Provozní hygiena ---
        $appUrl = $this->appUrl->status();
        $appUrlCheckStatus = match ($appUrl['state']) {
            AppUrlConfiguration::STATE_MISSING => $isSetupPreflight
                ? self::STATUS_OK
                : self::STATUS_FAIL,
            AppUrlConfiguration::STATE_INVALID => self::STATUS_FAIL,
            AppUrlConfiguration::STATE_HOSTNAME_CONFLICT => self::STATUS_FAIL,
            AppUrlConfiguration::STATE_ROUTING_ONLY => self::STATUS_WARN,
            default => self::STATUS_OK,
        };
        $checks[] = $this->check(
            'app_url',
            $appUrlCheckStatus,
            $appUrl['reason_code'],
            AppUrlConfiguration::REASON_VALID,
            '999_Reseni_problemu',
            $appUrl,
            $isSetupPreflight && $appUrl['state'] === AppUrlConfiguration::STATE_MISSING
                ? 'app_url_detected_during_setup'
                : '',
        );

        $isProd = ($runtime['app_env'] ?? '') === 'production';
        $checks[] = $this->check(
            'app_debug',
            !$isProd || empty($runtime['app_debug']) ? self::STATUS_OK : self::STATUS_WARN,
            !empty($runtime['app_debug']) ? 'zapnuto' : 'vypnuto',
            'vypnuto v produkci',
            '97_Bezpecnost'
        );

        $level = strtolower((string) ($runtime['logging_level'] ?? 'info'));
        $checks[] = $this->check(
            'logging_level',
            $isProd && $level === 'debug' ? self::STATUS_FAIL : self::STATUS_OK,
            $level,
            'info a výš v produkci',
            '97_Bezpecnost'
        );

        // --- Verze aplikace ---
        // Sahá na cache aktualizací (a přes ni potenciálně po síti), takže se
        // vůbec nepočítá, když o ni volající nestojí — třeba v preflightu.
        if ($onlyIds === null || in_array('app_version', $onlyIds, true)) {
            $status = $this->guard(fn () => $this->version->getStatus(), []);
            $hasUpdate = !empty($status['has_update']);
            $checks[] = $this->check(
                'app_version',
                $hasUpdate ? self::STATUS_WARN : self::STATUS_OK,
                (string) ($status['current'] ?? '?'),
                (string) ($status['latest'] ?? ($status['current'] ?? '?')),
                '98_Aktualizace',
                [
                    'current'     => $status['current'] ?? null,
                    'latest'      => $status['latest'] ?? null,
                    'release_url' => $status['release_url'] ?? null,
                ]
            );
        }

        if ($onlyIds !== null) {
            $checks = array_values(array_filter(
                $checks,
                static fn (array $check): bool => in_array($check['id'], $onlyIds, true),
            ));
        }

        return $checks;
    }

    // ── Pomocné ──────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $meta
     * @param string $info Zjištění, které není nález — ukazuje se v každém stavu
     *                     a nikdy nezvedá závažnost.
     * @return array<string,mixed>
     */
    private function check(string $id, string $status, string $actual, string $expected, string $manual, array $meta = [], string $info = ''): array
    {
        return [
            'id'       => $id,
            'status'   => $status,
            'actual'   => $actual,
            'expected' => $expected,
            'info'     => $info,
            'manual'   => $manual,
            'meta'     => $meta,
        ];
    }

    /**
     * Diagnostika nesmí spadnout na tom, co měří.
     *
     * @template T
     * @param callable():T $fn
     * @param T $fallback
     * @return T
     */
    private function guard(callable $fn, mixed $fallback): mixed
    {
        try {
            return $fn();
        } catch (\Throwable) {
            return $fallback;
        }
    }

    /** „11.8.8-MariaDB-log" → „11.8.8" */
    public static function numericVersion(string $version): string
    {
        return preg_match('/^(\d+(?:\.\d+){0,2})/', $version, $m) === 1 ? $m[1] : $version;
    }

    /** „256M" → bajty; „-1" (bez limitu) zůstává -1. */
    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        if ($value === '-1') {
            return -1;
        }
        $unit = strtolower(substr($value, -1));
        $num  = (int) $value;

        return match ($unit) {
            'g'     => $num * 1024 * 1024 * 1024,
            'm'     => $num * 1024 * 1024,
            'k'     => $num * 1024,
            default => $num,
        };
    }

    public static function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        foreach ([['G', 1024 ** 3], ['M', 1024 ** 2], ['k', 1024]] as [$suffix, $div]) {
            if ($bytes >= $div) {
                $value = $bytes / $div;
                return ($value >= 10 ? (string) round($value) : (string) round($value, 1)) . ' ' . $suffix . 'B';
            }
        }
        return $bytes . ' B';
    }

    /** Součet velikostí souborů v adresáři (nerekurzivně — logy jsou ploché). */
    private static function dirSize(string $dir): ?int
    {
        if (!is_dir($dir)) {
            return null;
        }
        $total = 0;
        foreach (glob(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file)) {
                $total += (int) @filesize($file);
            }
        }
        return $total;
    }
}
