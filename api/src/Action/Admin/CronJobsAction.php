<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Cron\CronCatalog;
use MyInvoice\Service\Cron\CronDispatcher;
use MyInvoice\Service\Cron\CronHealth;
use MyInvoice\Service\Cron\CronJobGate;
use MyInvoice\Service\Cron\CronScheduleMode;
use MyInvoice\Service\Cron\DockerCrontabGenerator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/admin/cron-jobs
 *
 * Vrací katalog doporučených plánovaných úloh + stav posledního běhu
 * z `cron_runs` (poslední běh, poslední úspěšný běh, ok? / overdue? / failing?).
 * Admin only.
 */
final class CronJobsAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::isSuperadmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }

        $pdo = $this->db->pdo();
        $gate = new CronJobGate($this->config, $pdo);
        // Spravovaná instalace má licenční obnovu hodinově; self-hosted denně.
        // Na režim se ptáme brány, ne konfigurace — `app.managed` čte jediné
        // místo v aplikaci ({@see ManagedModeGuard}), jinak by se podmínka
        // rozsypala po obrazovkách.
        $catalog = CronCatalog::forInstallation($gate->isManagedInstallation());
        $mode = CronScheduleMode::current($pdo);

        // Poslední stav se od migrace 1183 čte z cron_heartbeat — jeden řádek na
        // skript, přepisovaný při každém ticku. cron_runs už obsahuje jen běhy,
        // které něco udělaly, takže by z něj „kdy naposledy cron žil" nešlo zjistit
        // (úloha může korektně měsíc nemít co dělat a pořád být zdravá).
        $stmt = $pdo->prepare(
            "SELECT last_tick_at, last_started_at, last_finished_at, last_status,
                    last_duration_ms, last_exit_code, last_host, last_message,
                    last_report, last_ok_at, last_work_at, noop_ticks
               FROM cron_heartbeat
              WHERE script = ?"
        );

        // Počty za 24h zůstávají nad cron_runs, ale mají teď ostřejší význam:
        // „kolikrát úloha za den reálně něco udělala nebo selhala", ne „kolikrát
        // se spustila". Prázdné ticky se do historie nezapisují — jejich souhrn
        // nese `noop_ticks` z heartbeatu.
        $countStmt = $pdo->prepare(
            "SELECT
                SUM(status = 'ok')    AS ok_24h,
                SUM(status = 'error') AS err_24h,
                COUNT(*)              AS total_24h
              FROM cron_runs
             WHERE script = ?
               AND started_at >= NOW() - INTERVAL 24 HOUR"
        );

        $now = time();

        // Stáří instalace (od první migrace) — brána proti falešným poplachům
        // "nikdy neběželo" na čerstvé instalaci. Viz CronHealth::installAgeSec().
        $installAgeSec = null;
        try {
            $firstMigratedAt = $pdo->query('SELECT MIN(applied_at) FROM migrations')->fetchColumn();
            $installAgeSec = CronHealth::installAgeSec(
                is_string($firstMigratedAt) ? $firstMigratedAt : null,
                $now,
            );
        } catch (\Throwable) {
            // Tabulka migrations by měla existovat vždy (migrate.php ji zakládá
            // při prvním běhu) — pokud přesto dotaz selže, radši se chováme jako
            // dřív (bez PENDING relaxace) než abychom shodili celou stránku.
        }

        // V režimu dispatcheru je jeho heartbeat důkazem, že plánovací smyčka
        // žije — a tím pádem že ticho gatované úlohy (`cron-epo-status`,
        // `cron-ai-worker`) znamená „není práce", ne výpadek. Viz CronHealth.
        $dispatcherAlive = false;
        $gatedScripts = [];
        if ($mode === CronScheduleMode::DISPATCHER) {
            $stmt->execute([CronCatalog::DISPATCHER_SCRIPT]);
            $dispatcherHeartbeat = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
            $dispatcherAlive = CronHealth::isDispatcherAlive(
                $dispatcherHeartbeat,
                CronCatalog::maxAgeHours(CronCatalog::DISPATCHER_SCRIPT) * 3600,
                $now,
            );
            $gatedScripts = CronDispatcher::gatedScripts();
        }

        $rows = [];
        foreach ($catalog as $job) {
            $reason = $gate->inactiveReason($job, $mode);

            // Podmíněné úlohy (bank scan, scan inbox) skryj, dokud není nastaven
            // jejich adresář v cfg — bez něj scan jen tiše skipuje, takže nemá smysl
            // je v přehledu hlásit jako "nikdy neběžela". Výjimka je vypnutí přes
            // `cron.disabled_jobs` — to je explicitní vůle admina/spravované
            // instalace, takže se má v přehledu ukázat jako "vypnuto konfigurací",
            // ne zmizet stejně jako automaticky nerelevantní úlohy.
            if ($reason !== null && $reason !== CronJobGate::INACTIVE_DISABLED_BY_CONFIG) {
                continue;
            }

            $script = (string) $job['script'];

            if ($reason === CronJobGate::INACTIVE_DISABLED_BY_CONFIG) {
                $rows[] = [
                    'script'              => $script,
                    'recommended'         => $job['recommended'],
                    'linux_cron'          => $job['linux_cron'],
                    'windows_schtasks'    => $job['windows_schtasks'],
                    'weekdays_only'       => (bool) $job['weekdays_only'],
                    'critical'            => (bool) $job['critical'],
                    'max_age_hours'       => (int) $job['max_age_hours'],
                    'health'              => 'disabled',
                    'health_source'       => 'config',
                    'last_started_at'     => null,
                    'last_finished_at'    => null,
                    'last_status'         => null,
                    'last_duration_ms'    => null,
                    'last_exit_code'      => null,
                    'last_host'           => null,
                    'last_message'        => null,
                    'last_report'         => null,
                    'last_ok_started_at'  => null,
                    'last_ok_finished_at' => null,
                    'age_sec_since_ok'    => null,
                    'counts_24h'          => ['ok' => 0, 'error' => 0, 'total' => 0],
                    'last_tick_at'        => null,
                    'last_work_at'        => null,
                    'noop_ticks'          => 0,
                    'is_dispatcher'       => ($job['dispatcher_only'] ?? false) === true,
                    // Vypnutá úloha se nemá registrovat do crontabu/Task Scheduleru.
                    'scheduled_directly'  => false,
                ];
                continue;
            }

            $stmt->execute([$script]);
            $last = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;

            $countStmt->execute([$script]);
            $counts = $countStmt->fetch(\PDO::FETCH_ASSOC) ?: ['ok_24h' => 0, 'err_24h' => 0, 'total_24h' => 0];

            // Prázdný tick ('noop') je pro účely zdraví úspěch — cron žije a
            // korektně zjistil, že nemá co dělat. Proto se do last_ok_at počítá.
            $lastOkAt = $last['last_ok_at'] ?? null;
            $ageSec = $lastOkAt !== null ? $now - (int) strtotime((string) $lastOkAt) : null;

            [$health, $healthSource] = CronHealth::evaluate(
                $ageSec,
                $last['last_status'] ?? null,
                (int) $job['max_age_hours'] * 3600,
                in_array($script, $gatedScripts, true),
                $dispatcherAlive,
                $installAgeSec,
            );

            $report = null;
            if ($last !== null && !empty($last['last_report'])) {
                $report = json_decode((string) $last['last_report'], true);
            }

            $rows[] = [
                'script'              => $script,
                'recommended'         => $job['recommended'],
                'linux_cron'          => $job['linux_cron'],
                'windows_schtasks'    => $job['windows_schtasks'],
                'weekdays_only'       => (bool) $job['weekdays_only'],
                'critical'            => (bool) $job['critical'],
                'max_age_hours'       => (int) $job['max_age_hours'],
                'health'              => $health,                          // ok | idle | pending | overdue | failing | overdue_and_failing | never_ran
                // Podle čeho se stav určil: 'self' = vlastní heartbeat úlohy,
                // 'dispatcher' = úloha nemá práci a živý je za ni dispatcher.
                'health_source'       => $healthSource,
                'last_started_at'     => $last['last_started_at']    ?? null,
                'last_finished_at'    => $last['last_finished_at']   ?? null,
                'last_status'         => $last['last_status']        ?? null,
                'last_duration_ms'    => $last !== null && $last['last_duration_ms'] !== null ? (int) $last['last_duration_ms'] : null,
                'last_exit_code'      => $last !== null && $last['last_exit_code']   !== null ? (int) $last['last_exit_code']   : null,
                'last_host'           => $last['last_host']          ?? null,
                'last_message'        => $last['last_message']       ?? null,
                'last_report'         => $report,
                'last_ok_started_at'  => $lastOkAt,
                'last_ok_finished_at' => $lastOkAt,
                'age_sec_since_ok'    => $ageSec,
                'counts_24h'          => [
                    'ok'    => (int) ($counts['ok_24h']  ?? 0),
                    'error' => (int) ($counts['err_24h'] ?? 0),
                    'total' => (int) ($counts['total_24h'] ?? 0),
                ],
                // Nové od migrace 1183 — odlišuje „cron žije" od „cron pracuje".
                'last_tick_at'        => $last['last_tick_at']  ?? null,
                'last_work_at'        => $last['last_work_at']  ?? null,
                'noop_ticks'          => $last !== null ? (int) ($last['noop_ticks'] ?? 0) : 0,
                // Je to sám plánovač?
                'is_dispatcher'       => ($job['dispatcher_only'] ?? false) === true,
                // Má ji admin registrovat do crontabu/Task Scheduleru sám? V režimu
                // dispatcheru NE — spouští ji dispatcher, a návod s `linux_cron`
                // by sváděl k tomu ji zaregistrovat znovu (a spustit dvakrát).
                'scheduled_directly'  => $mode === CronScheduleMode::INDIVIDUAL
                    || ($job['dispatcher_only'] ?? false) === true,
            ];
        }

        // Server time pro UI (klient může mít jinou TZ než server).
        return Json::ok($response, [
            'jobs'        => $rows,
            'server_time' => date('c'),
            'install'     => $this->installContext(),
            'schedule'    => $this->scheduleContext($pdo),
        ]);
    }

    /**
     * Podklady pro přepínač režimu plánování.
     *
     * `dispatcher_script` a `individual_count` jdou ven proto, aby si UI mohlo
     * poskládat konkrétní návod („zaregistruj tuhle jednu úlohu místo těch 18")
     * bez toho, aby duplikovalo katalog.
     *
     * @return array{mode:string,modes:list<string>,dispatcher_script:string,individual_count:int,requires_replan:bool}
     */
    private function scheduleContext(\PDO $pdo): array
    {
        return [
            'mode'              => CronScheduleMode::current($pdo),
            'modes'             => CronScheduleMode::all(),
            'dispatcher_script' => CronCatalog::DISPATCHER_SCRIPT,
            'individual_count'  => count(CronCatalog::dispatchable()),
            // Zápis do DB sám nic nepřeplánuje — crontab / Task Scheduler se musí
            // přegenerovat (v Dockeru restartem kontejneru). UI na to musí upozornit,
            // jinak admin přepne režim a diví se, že se nic nestalo.
            'requires_replan'   => true,
        ];
    }

    /**
     * Podklady pro návod „jak úlohy naplánovat" v UI. Cesty se berou ze SKUTEČNÉHO
     * běžícího nasazení (`Bootstrap::rootDir()`), ne z příkladů v dokumentaci — jinak
     * si je admin musí přepisovat ručně a udělá překlep.
     *
     * @return array{
     *   project_root:string, cmd_dir:string, log_dir:string, os_family:string,
     *   is_docker:bool, data_dir:?string, php_binary:string, docker_managed:bool
     * }
     */
    private function installContext(): array
    {
        $root = \MyInvoice\Bootstrap::rootDir();
        $dataDir = getenv('MYINVOICE_DATA_DIR');
        $dataDir = is_string($dataDir) && trim($dataDir) !== '' ? trim($dataDir) : null;
        // V Docker image plánuje úlohy vestavěný cron generovaný z CronCatalog
        // (DockerCrontabGenerator) — admin tam nemá co nastavovat, jen ověřit.
        $isDocker = is_file('/.dockerenv') || is_file(DockerCrontabGenerator::WRAPPER);

        $windows = PHP_OS_FAMILY === 'Windows';
        $sep = $windows ? '\\' : '/';

        return [
            'project_root'   => $root,
            'cmd_dir'        => $root . $sep . 'cmd',
            'log_dir'        => ($dataDir ?? $root) . $sep . 'log' . $sep . 'cron',
            'os_family'      => PHP_OS_FAMILY,
            'is_docker'      => $isDocker,
            'data_dir'       => $dataDir,
            'php_binary'     => self::cliPhpBinary(),
            'docker_managed' => $isDocker,
        ];
    }

    /**
     * PHP_BINARY je binárka WEBOVÉHO SAPI — pod IIS/FastCGI `php-cgi.exe`, pod
     * FPM `php-fpm`. Cron potřebuje CLI. Zobrazit sem web SAPI by svádělo dát ho
     * do plánované úlohy, kde se chová jinak (CGI hlavičky, jiné php.ini sekce),
     * takže hledáme sourozence php/php.exe ve stejném adresáři.
     */
    private static function cliPhpBinary(): string
    {
        $binary = PHP_BINARY;
        $base = basename($binary);
        if (!preg_match('/^php-(cgi|fpm)(\d[\d.]*)?(\.exe)?$/i', $base)) {
            return $binary;
        }
        $dir = dirname($binary);
        foreach (['php.exe', 'php'] as $candidate) {
            $path = $dir . DIRECTORY_SEPARATOR . $candidate;
            if (is_file($path)) {
                return $path;
            }
        }
        return $binary;
    }

}
