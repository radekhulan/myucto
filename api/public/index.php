<?php

declare(strict_types=1);

/**
 * Brána údržby — MUSÍ zůstat nad `require autoload.php` a nesmí sáhnout na
 * žádnou třídu aplikace. Přesně v okně, které hlídá (swap souborů + migrace),
 * je autoloader nekonzistentní: mapa tříd ukazuje na soubory, které ještě
 * nedorazily. Kdyby brána potřebovala autoload, spadla by dřív, než odpoví.
 *
 * Značku zakládá a maže {@see \MyInvoice\Service\Update\MaintenanceMode}.
 * Formát sdílí s MyInvoice.cz: při přechodu na nástupce ji zakládá ještě starý
 * kód, swap pak vymění tenhle soubor a okno musí držet dál — migrace běží
 * dlouho po swapu.
 */
(static function (): void {
    $base = getenv('MYINVOICE_DATA_DIR');
    $base = is_string($base) && trim($base) !== ''
        ? rtrim(trim($base), "/\\")
        : dirname(__DIR__, 2);

    $raw = @file_get_contents($base . '/storage/maintenance.json');
    if (!is_string($raw) || $raw === '') {
        return;
    }
    $flag = json_decode($raw, true);
    if (!is_array($flag)) {
        return;
    }

    // Mrtvá značka po spadlém workeru nesmí držet instalaci dole navěky.
    $expires = strtotime((string) ($flag['expires_at'] ?? ''));
    if ($expires === false || $expires < time()) {
        return;
    }

    $product = (string) ($flag['product'] ?? 'aplikace');
    $target  = (string) ($flag['target'] ?? '');
    $message = 'Probíhá aktualizace na ' . $product . ($target !== '' ? ' ' . $target : '')
        . '. Zkus to prosím za chvíli — nasazení souborů a migrace databáze trvají jednotky minut.';

    $uri  = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $qs   = strpos($uri, '?');
    $path = $qs === false ? $uri : substr($uri, 0, $qs);

    // Zdravotní rozhraní musí přežít i okno aktualizace (příloha B.7): jinak
    // orchestrátor na straně provozovatele nepozná rozdíl mezi „nasazuje se"
    // a „instance je mrtvá" a nemá jak zjistit, jestli nasazení doběhlo.
    // Odpověď se skládá RUČNĚ, bez autoloaderu a bez DB — v tomhle okně je
    // mapa tříd nekonzistentní a schéma rozpracované, takže plný health
    // (HealthAction) není k dispozici.
    // Klíče jsou proto podmnožinou plného healthu a co neumíme zjistit, je
    // explicitní `null` — nikdy chybějící klíč, aby se klient nemusel ptát,
    // jestli hodnotu nezná, nebo mu ji jen tahle větev zapomněla poslat.
    if ($path === '/api/health') {
        http_response_code(200);
        header('Cache-Control: no-store');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status'      => 'ok',
            'maintenance' => true,
            'update'      => [
                'target'     => $target !== '' ? $target : null,
                'started_at' => ($flag['started_at'] ?? null) !== null ? (string) $flag['started_at'] : null,
            ],
            'db'      => null,
            'version' => null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(503);
    header('Retry-After: 20');
    header('Cache-Control: no-store');

    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    if (str_starts_with($path, '/api/') || stripos($accept, 'application/json') !== false) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            ['error' => ['code' => 'maintenance', 'message' => $message]],
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="cs"><head><meta charset="utf-8">'
        . '<meta http-equiv="refresh" content="20">'
        . '<title>Probíhá aktualizace</title>'
        . '<style>body{font:14px/1.6 system-ui,sans-serif;max-width:560px;margin:80px auto;padding:0 20px;color:#15131D}'
        . 'h1{color:#3B2D83;font-size:22px}</style></head><body>'
        . '<h1>Probíhá aktualizace</h1><p>' . htmlspecialchars($message, ENT_QUOTES) . '</p>'
        . '<p>Stránka se sama obnoví.</p></body></html>';
    exit;
})();

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Http\AfterResponseTasks;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Http\MaintenanceResponse;
use MyInvoice\Http\RequestPath;
use MyInvoice\Middleware\FirstRunLockMiddleware;
use MyInvoice\Service\System\MaintenanceLock;
use MyInvoice\Service\Tenant\TenantDomainPolicy;
use MyInvoice\Service\Tenant\TenantDomainResolver;
use Slim\Psr7\Factory\ServerRequestFactory;

try {
    $requestPath = RequestPath::normalize((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'));
    if (!str_starts_with($requestPath, '/api/')
        && $requestPath !== '/mcp'
        && !in_array($requestPath, ['/oauth/authorize', '/oauth/token', '/oauth/register'], true)
        && !str_starts_with($requestPath, '/.well-known/oauth-')
    ) {
        // H-03: zámek údržby pro SPA fallback.
        //
        // Tahle větev se do Slim pipeline NIKDY nedostane — front controller
        // vydává `web/dist/index.html` ještě před `Bootstrap::buildApp()`, takže
        // MaintenanceModeMiddleware by ji minul a v údržbě by uživatel dostal
        // normální aplikaci, která pak na prvním API volání spadne na 503.
        // Odpověď je proto sdílená ({@see MaintenanceResponse}) a stejná jako
        // z middlewaru: čitelná stránka pro prohlížeč, JSON pro API klienta.
        //
        // Nečitelná konfigurace tady údržbu NEZAPÍNÁ — chybu vyřeší catch níž
        // s pořádnou hláškou; 503 „údržba" by ji jen zamaskovalo.
        $maintenanceLock = null;
        try {
            $candidate = new MaintenanceLock(Config::load(Bootstrap::rootDir()));
            $maintenanceLock = $candidate->isActive() ? $candidate : null;
        } catch (\Throwable) {
            $maintenanceLock = null;
        }
        if ($maintenanceLock !== null) {
            $retryAfter = $maintenanceLock->retryAfter();
            $message = $maintenanceLock->message();
            http_response_code(503);
            header('Retry-After: ' . $retryAfter);
            header('Cache-Control: no-store');
            if (MaintenanceResponse::wantsJson($requestPath, (string) ($_SERVER['HTTP_ACCEPT'] ?? ''))) {
                header('Content-Type: application/json; charset=utf-8');
                echo MaintenanceResponse::json($message);
                exit;
            }
            header('Content-Type: text/html; charset=utf-8');
            echo MaintenanceResponse::html($message, $retryAfter);
            exit;
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            http_response_code(405);
            header('Allow: GET, HEAD');
            exit;
        }

        // SPA fallback jde přes stejnou host-to-tenant politiku jako API. Webserver
        // proto nevrátí index.html neznámému nebo neaktivnímu hostname dřív, než
        // se frontend vůbec dostane k /api/auth/domain-context.
        //
        // S vypnutými doménami (default) je celý gate no-op, takže se vyhneme
        // i sestavení kontejneru a dotazu do DB. Každé načtení stránky by jinak
        // platilo boot aplikace za rozhodnutí, které je předem známé.
        $domainsEnabled = false;
        try {
            $domainsEnabled = Config::load(Bootstrap::rootDir())->get('domains.enabled', false) === true;
        } catch (\Throwable) {
            // Nečitelná konfigurace se řeší níž v plné aplikaci s pořádnou hláškou.
            $domainsEnabled = false;
        }
        $container = $domainsEnabled ? Bootstrap::buildContainer() : null;
        // Před prvním adminem musí wizard zůstat dostupný i přes LAN hostname,
        // protože app.url se často nastaví až během setupu. API přitom dál omezuje
        // FirstRunLock na svůj úzký allowlist.
        if ($container !== null && !$container->get(FirstRunLockMiddleware::class)->needsSetup()) {
            $request = ServerRequestFactory::createFromGlobals();
            $context = $container->get(TenantDomainResolver::class)->resolve($request);
            $denial = $container->get(TenantDomainPolicy::class)->denial($context, $method, $requestPath);
            if ($denial !== null) {
                http_response_code($denial['status']);
                header('Content-Type: application/json; charset=utf-8');
                header('Cache-Control: no-store');
                echo json_encode(['error' => [
                    'code' => $denial['code'],
                    'message' => $denial['message'],
                ]], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        $indexFile = Bootstrap::rootDir() . '/web/dist/index.html';
        if (!is_file($indexFile)) {
            throw new \RuntimeException('Frontend build chybí; spusť `cd web && pnpm build`.');
        }
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        if ($method !== 'HEAD') {
            readfile($indexFile);
        }
        exit;
    }

    $app = Bootstrap::buildApp();
    $app->run();
    // Práce, jejíž délka nesmí být znát na odpovědi (např. e-mail obnovy hesla).
    AfterResponseTasks::runPending();
} catch (\Throwable $e) {
    // Pre-bootstrap chyba (typicky chybějící cfg.php nebo nedostupná DB).
    http_response_code(503);

    $msg = $e->getMessage();
    $missingCfg = str_contains($msg, 'cfg.php');
    $isJson = isset($_SERVER['HTTP_ACCEPT']) && stripos((string) $_SERVER['HTTP_ACCEPT'], 'application/json') !== false;

    // Detekce produkce — pokud cfg.php existuje a obsahuje 'env' => 'production',
    // skryjeme detail. Bez cfg.php (úplně čerstvá instalace) ukážeme návod, abychom pomohli adminovi.
    $isProd = false;
    $cfgFile = __DIR__ . '/../../cfg.php';
    if (is_file($cfgFile)) {
        $cfgContent = (string) @file_get_contents($cfgFile);
        $isProd = !preg_match("/'env'\s*=>\s*'development'/", $cfgContent);
    }

    // Detail loguj, pokud je dostupný adresář.
    $logDir = \MyInvoice\Infrastructure\Config\RuntimePaths::log();
    if (is_dir($logDir) && is_writable($logDir)) {
        @file_put_contents(
            $logDir . '/bootstrap-error.log',
            sprintf("[%s] %s\n%s\n\n", date('Y-m-d H:i:s'), $msg, $e->getTraceAsString()),
            FILE_APPEND
        );
    }

    $publicMsg = ($isProd && !$missingCfg)
        ? 'Aplikace nedostupná, kontaktujte administrátora.'
        : $msg;
    $publicCode = $missingCfg ? 'config_missing' : 'bootstrap_failed';

    if ($isJson) {
        header('Content-Type: application/json; charset=utf-8');
        $payload = ['error' => ['code' => $publicCode, 'message' => $publicMsg]];
        if (!$isProd && $missingCfg) {
            $payload['error']['hint'] = 'Vytvoř cfg.php z cfg.sample.php a spusť `php api/bin/setup.php`.';
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!doctype html>
    <html lang="cs">
    <head>
      <meta charset="utf-8">
      <title>MyÚčto.cz</title>
      <style>
        body { font: 14px/1.5 system-ui, sans-serif; max-width: 640px; margin: 60px auto; padding: 0 20px; color: #15131D; }
        h1 { color: #3B2D83; }
        code { background: #F4F2F8; padding: 2px 6px; border-radius: 4px; font-family: 'Geist Mono', ui-monospace, Consolas, monospace; }
        pre { background: #15131D; color: #fff; padding: 12px 16px; border-radius: 6px; overflow-x: auto; }
        .err { background: #FBEDED; border-left: 3px solid #D45B5B; padding: 12px; margin: 16px 0; }
      </style>
    </head>
    <body>
      <?php if ($isProd && !$missingCfg): ?>
        <h1>Aplikace nedostupná</h1>
        <p>Došlo k dočasné chybě. Kontaktujte administrátora.</p>
      <?php else: ?>
        <h1>MyÚčto.cz — chybí konfigurace</h1>
        <div class="err"><strong>Chyba:</strong> <?= htmlspecialchars($publicMsg, ENT_QUOTES) ?></div>
        <?php if ($missingCfg): ?>
          <h2>Postup</h2>
          <ol>
            <li>Z rootu repa zkopíruj vzorovou konfiguraci:
              <pre>cp cfg.sample.php cfg.php</pre>
            </li>
            <li>Otevři <code>cfg.php</code> a vyplň hodnoty <code>CHANGE-ME</code> — minimálně:
              <ul>
                <li><code>app.url</code> — tvoje doména</li>
                <li><code>app.pepper</code> — vygeneruj: <code>openssl rand -base64 32</code></li>
                <li><code>db.host</code> / <code>db.name</code> / <code>db.user</code> / <code>db.pass</code></li>
                <li><code>smtp.*</code> pro odesílání e-mailů</li>
              </ul>
            </li>
            <li>Spusť úvodní nastavení:
              <pre>php api/bin/setup.php</pre>
            </li>
          </ol>
        <?php else: ?>
          <h2>Pravděpodobné příčiny</h2>
          <ul>
            <li>Neplatné údaje v <code>cfg.php</code> (db.host / user / pass)</li>
            <li>MariaDB neběží nebo je na jiném portu</li>
            <li>Databáze neexistuje</li>
          </ul>
        <?php endif; ?>
      <?php endif; ?>
    </body>
    </html>
    <?php
}
