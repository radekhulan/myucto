<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin;

use MyInvoice\Bootstrap;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Cron\CronCatalog;
use MyInvoice\Service\System\ManagedModeGuard;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/admin/cron-jobs/{script}/run
 *
 * Spustí daný cron skript z katalogu na pozadí (fire-and-forget).
 * Skript si sám zapíše start/finish do `cron_runs` přes CronRun, takže
 * UI se aktualizuje automaticky při refresh tabulky.
 *
 * Stdout/stderr spawnutého procesu se přesměruje do `log/cron-run-<script>.log`
 * pro diagnostiku (kdyby fail nastal před tím, než CronRun stihne otevřít DB).
 * Admin only.
 */
final class RunCronJobAction
{
    /** Tyto cron skripty obcházejí HTTP akce, které už mají vlastní zámek. */
    private const FILESYSTEM_SCAN_SCRIPTS = ['cron-bank-scan', 'cron-scan-purchase-inbox'];

    public function __construct(
        private readonly ActivityLogger $logger,
        private readonly Config $config,
        private readonly ManagedModeGuard $managed,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::isSuperadmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }

        $script = (string) ($args['script'] ?? '');
        if (!in_array($script, CronCatalog::scripts(), true)) {
            return Json::error($response, 'not_found', 'Neznámý cron skript.', 404);
        }
        if (in_array($script, self::FILESYSTEM_SCAN_SCRIPTS, true)
            && ($locked = $this->managed->deny($response, ManagedModeGuard::CAPABILITY_FILESYSTEM_SCAN)) !== null) {
            return $locked;
        }

        $rootDir = Bootstrap::rootDir();
        $scriptPath = $rootDir . '/api/bin/' . $script . '.php';
        if (!is_file($scriptPath)) {
            // ⚠️ Cesta jde do LOGU, ne do odpovědi. Ve spravovaném provozu je to
            // rozložení serveru provozovatele, a to nájemníkovi nepatří —
            // nepřímo z něj plyne i to, kdo instalaci hostuje.
            $this->appendLog($this->logPath($script), sprintf(
                "[%s] chybí soubor skriptu: %s\n",
                date('Y-m-d H:i:s'),
                $scriptPath,
            ));
            return Json::error($response, 'not_found', 'Soubor cron skriptu chybí. Detail je v logu instalace.', 404);
        }

        $phpBin = $this->resolveCliPhpBinary();
        if ($phpBin === null) {
            return Json::error(
                $response,
                'no_php_cli',
                'PHP CLI binárka nenalezena. Nastav prosím cestu k php.exe; detail je v logu instalace.',
                500
            );
        }

        $logPath = $this->logPath($script);
        $this->appendLog($logPath, sprintf(
            "[%s] spawn: php=%s script=%s sapi=%s\n",
            date('c'),
            $phpBin,
            $scriptPath,
            PHP_SAPI
        ));

        $spawned = $this->spawnBackground($phpBin, $scriptPath, $logPath, $rootDir, $diag);

        // ⚠️ Do auditu ani do odpovědi nepatří absolutní cesty (PHP, log, kořen
        // webu): audit čte každý admin a ve spravovaném provozu jde o rozložení
        // serveru provozovatele. Úplné cesty zůstávají jen v logu instalace výše.
        $diag = self::withoutPaths((string) $diag);
        $this->logger->log(
            'admin.cron.run_now',
            (int) ($user['id'] ?? 0),
            null,
            null,
            [
                'script'   => $script,
                'spawned'  => $spawned,
                'log_file' => basename($logPath),
                'diag'     => $diag,
            ]
        );

        if (!$spawned) {
            return Json::error($response, 'spawn_failed', 'Nepodařilo se spustit skript na pozadí: ' . $diag, 500);
        }

        return Json::ok($response, [
            'script'   => $script,
            'started'  => true,
            'log_file' => basename($logPath),
        ], 202);
    }

    /** Nahradí absolutní cesty (Windows i Unix) v diagnostickém textu jejich názvem souboru. */
    public static function withoutPaths(string $text): string
    {
        return (string) preg_replace_callback(
            '~(?:[A-Za-z]:[\\\\/]|/)[^\s()=,;"\']*~',
            static fn (array $m): string => basename(str_replace('\\', '/', $m[0])),
            $text,
        );
    }

    /**
     * Pod IIS FastCGI je PHP_BINARY = php-cgi.exe. CLI skripty v takovém prostředí
     * tiše končí. Sdílená logika ve PhpCliLocator (používá i import worker spawn).
     */
    private function resolveCliPhpBinary(): ?string
    {
        return \MyInvoice\Service\PhpCliLocator::resolve();
    }

    private function logPath(string $script): string
    {
        $dataDir = $this->config->dataDir() ?? Bootstrap::rootDir();
        $logDir = rtrim($dataDir, "\\/") . DIRECTORY_SEPARATOR . 'log';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0o775, true);
        }
        return $logDir . DIRECTORY_SEPARATOR . 'cron-run-' . $script . '.log';
    }

    private function appendLog(string $path, string $text): void
    {
        @file_put_contents($path, $text, FILE_APPEND | LOCK_EX);
    }

    /**
     * Spustí PHP CLI skript na pozadí, fire-and-forget.
     * `$diag` dostane krátký popis (pro activity_log).
     */
    private function spawnBackground(string $phpBin, string $scriptPath, string $logPath, string $cwd, ?string &$diag): bool
    {
        // Sdílený launcher (stejný i pro import workery). $phpBin už je resolved
        // přes PhpCliLocator; helper si ho dohledá stejně, takže zůstává konzistentní.
        return \MyInvoice\Service\BackgroundProcess::spawnPhp($scriptPath, [], $logPath, $cwd, $diag);
    }
}
