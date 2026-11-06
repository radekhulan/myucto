<?php

declare(strict_types=1);

/**
 * cmd/check-openapi-coverage.php
 *
 * Audit drift mezi Slim routes (api/src/Routes.php) a api/openapi.yaml.
 * Reportuje:
 *   - routes v kódu, které nejsou dokumentované → riziko, že je integrátoři minou
 *   - paths v openapi.yaml, které už v kódu neexistují → mrtvá dokumentace
 *
 * Veřejnost operace ověřuje skutečný ApiScopeMiddleware nad syntetickým bearer
 * požadavkem se scope read_write. Interní cesty a zakázané účetní/daňové zápisy
 * nejsou mezery veřejného kontraktu. Mzdový subset se kontroluje stejně jako
 * ostatní veřejné cesty, nikoli plošnou výjimkou pro /api/payroll/*.
 * Další session guardy v actions jsou výjimky jednotlivých symbolů níže.
 * Kurátorské výjimky: mutace číselníků, nastavení a firem, dokumentační endpointy
 * a explicitní testovací tooling. Již dokumentované session-only paths dál
 * kontroluje proti routeru, aby se z nich nestala mrtvá dokumentace.
 *
 * Extrakce routes: skupiny $app->group('/prefix', fn) se párují závorkově
 * (per-group scope). NEporovnávat $g-> globálně přes všechny prefixy —
 * to cross-multiplikuje routes a generuje falešné nálezy.
 *
 * Exit kódy:
 *   0 = bez nálezů
 *   1 = mismatch nalezen (CI warning, ne fail)
 */

$root = dirname(__DIR__);
require $root . '/api/vendor/autoload.php';

$options = getopt('', ['routes:', 'spec:']);
$routesFile  = $options['routes'] ?? $root . '/api/src/Routes.php';
$openapiFile = $options['spec'] ?? $root . '/api/openapi.yaml';

if (!is_file($routesFile))  { fwrite(STDERR, "ERR: missing $routesFile\n"); exit(2); }
if (!is_file($openapiFile)) { fwrite(STDERR, "ERR: missing $openapiFile\n"); exit(2); }

// --- 1) Extract routes z Routes.php ----------------------------------------
$src = (string) file_get_contents($routesFile);
$len = strlen($src);
$routes = [];
$imports = [];
preg_match_all('/^use\s+([\\\\\w]+)(?:\s+as\s+(\w+))?\s*;/m', $src, $uses, PREG_SET_ORDER);
foreach ($uses as $use) {
    $parts = explode('\\', $use[1]);
    $imports[$use[2] ?? end($parts)] = $use[1];
}
$routeAction = static function (string $tail) use ($imports): ?string {
    if (!preg_match('/^\s*,\s*(?:\[\s*)?([\\\\\w]+)::class(?:\s*,\s*[\'"](\w+)[\'"])?/', $tail, $match)) return null;
    $class = ltrim($match[1], '\\');
    $class = $imports[$class] ?? $class;
    return $class . '::' . ($match[2] ?? '__invoke');
};

// 1a) Najdi každou skupinu $app->group('/prefix', function ($g) { ... }) a spáruj
//     složené závorky, aby se $g-> routes přiřadily jen VLASTNÍ skupině.
//     (Dřív se naivně párovaly všechny $g-> v souboru pod každý prefix →
//      cross-multiplikace routes přes všechny group prefixy = falešné nálezy.)
$groupRanges = []; // [prefix, bodyStart, bodyEnd]
$offset = 0;
while (preg_match(
    '/\$app->group\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*function\s*\([^)]*\)\s*(?:use\s*\([^)]*\)\s*)?\{/',
    substr($src, $offset),
    $gm,
    PREG_OFFSET_CAPTURE
)) {
    $prefix   = $gm[1][0];
    $bracePos = $offset + $gm[0][1] + strlen($gm[0][0]) - 1; // pozice otevírací {
    $depth = 0; $end = $bracePos;
    for ($i = $bracePos; $i < $len; $i++) {
        if ($src[$i] === '{') $depth++;
        elseif ($src[$i] === '}') { if (--$depth === 0) { $end = $i; break; } }
    }
    $groupRanges[] = [$prefix, $bracePos, $end];
    $offset = $end + 1;
}
foreach ($groupRanges as [$prefix, $start, $end]) {
    $body = substr($src, $start, $end - $start + 1);
    if (preg_match_all(
        '/\$g->(get|post|put|patch|delete|any)\s*\(\s*[\'"]([^\'"]+)[\'"]/i',
        $body,
        $im,
        PREG_SET_ORDER | PREG_OFFSET_CAPTURE
    )) {
        foreach ($im as $hit) {
            $routes[] = ['method' => strtoupper($hit[1][0]), 'path' => $prefix . $hit[2][0],
                'action' => $routeAction(substr($body, $hit[0][1] + strlen($hit[0][0])))];
        }
    }
}

// 1b) Plain $app-> routes MIMO skupiny — vymaskuj těla skupin, ať se
//     $g-> volání nechytnou jako $app-> (a naopak zůstanou jen top-level routes).
$masked = $src;
foreach ($groupRanges as [$prefix, $start, $end]) {
    $masked = substr_replace($masked, str_repeat(' ', $end - $start + 1), $start, $end - $start + 1);
}
if (preg_match_all(
    '/\$app->(get|post|put|patch|delete|any)\s*\(\s*[\'"]([^\'"]+)[\'"]/i',
    $masked,
    $m,
    PREG_SET_ORDER | PREG_OFFSET_CAPTURE
)) {
    foreach ($m as $hit) {
        $routes[] = ['method' => strtoupper($hit[1][0]), 'path' => $hit[2][0],
            'action' => $routeAction(substr($masked, $hit[0][1] + strlen($hit[0][0])))];
    }
}

if (str_contains($src, '\\MyInvoice\\Routes\\PropertyAccountingCatalogRoutes::register($app)')) {
    $registrarApp = \Slim\Factory\AppFactory::create();
    \MyInvoice\Routes\PropertyAccountingCatalogRoutes::register($registrarApp);
    foreach ($registrarApp->getRouteCollector()->getRoutes() as $route) {
        $callable = $route->getCallable();
        $action = is_array($callable) ? $callable[0] . '::' . $callable[1] : null;
        foreach ($route->getMethods() as $method) {
            $routes[] = ['method' => $method, 'path' => $route->getPattern(), 'action' => $action];
        }
    }
}

// Normalize placeholdery: `{id:[0-9]+}` → `{id}`, `{entity_type:invoice|work_report}` → `{entity_type}`
// Musí umět i jednu úroveň vnořených složených závorek (regex kvantifikátory typu
// `{date:\d{4}-\d{2}-\d{2}}` nebo `{batchId:[a-fA-F0-9]{32}}`) — jinak se placeholder
// oseká na první vnitřní `}` a zbytek regexu zůstane v cestě jako text.
$expanded = [];
foreach ($routes as $r) {
    $variants = [$r['path']];
    preg_match_all('/\{(\w+):((?:[^{}]|\{[^{}]*\})*)\}/', $r['path'], $constraints, PREG_SET_ORDER);
    foreach ($constraints as $constraint) {
        $values = preg_match('/^[A-Za-z0-9._-]+(?:\|[A-Za-z0-9._-]+)+$/D', $constraint[2]) === 1
            ? explode('|', $constraint[2]) : ['{' . $constraint[1] . '}'];
        $next = [];
        foreach ($variants as $variant) {
            foreach ($values as $value) $next[] = str_replace($constraint[0], $value, $variant);
        }
        $variants = $next;
    }
    $template = preg_replace('/\{(\w+):(?:[^{}]|\{[^{}]*\})*\}/', '{$1}', $r['path']);
    foreach ($variants as $variant) $expanded[] = array_replace($r, ['path' => $variant, 'template' => $template]);
}
$routes = $expanded;

// Dedupe (identický method+path se může objevit víckrát)
$seen = [];
$routes = array_values(array_filter($routes, static function ($r) use (&$seen) {
    $k = $r['method'] . ' ' . $r['path'];
    if (isset($seen[$k])) return false;
    $seen[$k] = true;
    return true;
}));

// --- 2) Endpoints, které vědomě neaudituji ---------------------------------
$skipExact = [
    '/api/openapi.yaml',
    '/api/docs',
    '/api/reference',
    '/api/scalar',
    '/api/health',          // dokumentované, ale alias /api/v1/health
    '/api/version',
    '/api/invoices/preview-varsymbol', // admin tooling
    '/api/invoices/{id}/send-test',
    '/api/invoices/{id}/reminder-test',
    '/api/invoices/{id}/request-approval-test',
    '/api/settings/email-profiles', // admin: konfigurace odesílacích e-mail profilů
    '/api/{path}',  // catch-all 404 fallback
];

$shouldSkip = function (string $path) use ($skipExact): bool {
    return in_array($path, $skipExact, true);
};

// Settings/suppliers mutace (POST/PUT/DELETE) — záměrně mimo public API
$isSettingsMutation = function (string $method, string $path): bool {
    if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
        if (preg_match('#^/api/(settings|suppliers)(/|$)#', $path)) return true;
    }
    return false;
};

$policy = new \MyInvoice\Middleware\ApiScopeMiddleware(new \Slim\Psr7\Factory\ResponseFactory());
$requestFactory = new \Slim\Psr7\Factory\ServerRequestFactory();
$policyProbe = new class implements \Psr\Http\Server\RequestHandlerInterface {
    public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        return new \Slim\Psr7\Response(204);
    }
};
$isPublic = static function (string $method, string $path) use ($policy, $requestFactory, $policyProbe): bool {
    $sample = preg_replace('/\{\w+\}/', '1', $path);
    $request = $requestFactory->createServerRequest($method, $sample)
        ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD, 'bearer')
        ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_API_TOKEN, ['scope' => 'read_write']);
    return $policy->process($request, $policyProbe)->getStatusCode() === 204;
};
$priceListMutations = [
    'MyInvoice\\Action\\PriceList\\PriceListItemAction::create',
    'MyInvoice\\Action\\PriceList\\PriceListItemAction::update',
    'MyInvoice\\Action\\PriceList\\PriceListItemAction::delete',
    'MyInvoice\\Action\\PriceList\\PriceListItemAction::upsertPrice',
    'MyInvoice\\Action\\PriceList\\PriceListItemAction::deletePrice',
    'MyInvoice\\Action\\PriceList\\PriceListItemAction::upsertCustomerOverride',
    'MyInvoice\\Action\\PriceList\\PriceListItemAction::deleteCustomerOverride',
];
$isCodebookMutation = static fn (array $route): bool
    => $route['method'] !== 'GET' && (str_starts_with($route['path'], '/api/codebooks/')
        || in_array($route['action'], $priceListMutations, true));
$sessionActions = [
    'MyInvoice\\Action\\Stock\\ProductAssemblyAction::list',
    'MyInvoice\\Action\\Stock\\ProductAssemblyAction::get',
    'MyInvoice\\Action\\Stock\\ProductAssemblyAction::create',
    'MyInvoice\\Action\\Stock\\ProductAssemblyAction::reverse',
    'MyInvoice\\Action\\Stock\\OpeningStockImportAction::upload',
    'MyInvoice\\Action\\Stock\\OpeningStockImportAction::preview',
    'MyInvoice\\Action\\Stock\\OpeningStockImportAction::apply',
    'MyInvoice\\Action\\Eshop\\ProductSetAction::get',
    'MyInvoice\\Action\\Eshop\\ProductSetAction::save',
    'MyInvoice\\Action\\Eshop\\ProductSetAction::quote',
    'MyInvoice\\Action\\Eshop\\CatalogJobAction::change',
    'MyInvoice\\Action\\Eshop\\CatalogImportAction::upload',
    'MyInvoice\\Action\\Eshop\\CatalogImportAction::saveProfile',
    'MyInvoice\\Action\\Eshop\\CatalogImportAction::preview',
    'MyInvoice\\Action\\Eshop\\CatalogImportAction::apply',
    'MyInvoice\\Action\\Eshop\\ShoptetAction::saveSettings',
    'MyInvoice\\Action\\Eshop\\ShoptetAction::setOrderUrl',
    'MyInvoice\\Action\\Eshop\\ShoptetAction::clearOrderUrl',
    'MyInvoice\\Action\\Eshop\\ShoptetAction::previewOrders',
    'MyInvoice\\Action\\Eshop\\ShoptetAction::applyOrders',
    'MyInvoice\\Action\\Eshop\\ShoptetAction::discardOrders',
    'MyInvoice\\Action\\Eshop\\ShoptetAction::eraseOrders',
    'MyInvoice\\Action\\Eshop\\ShoptetAction::markReviewed',
    'MyInvoice\\Action\\Eshop\\ShoptetAction::importDocuments',
    'MyInvoice\\Action\\Eshop\\ShoptetAction::rotateFeed',
    'MyInvoice\\Action\\Eshop\\ShoptetAction::disableFeed',
    'MyInvoice\\Action\\PurchaseInvoice\\PaymentOrderAction::archive',
    // Podpis dávky v bance je přesměrování prohlížeče; API token ho dokončit nemůže.
    'MyInvoice\\Action\\PurchaseInvoice\\BankPaymentOrderSubmissionAction::sign',
    'MyInvoice\\Action\\PurchaseInvoice\\BankPaymentOrderSubmissionAction::refresh',
    'MyInvoice\\Action\\PurchaseInvoice\\BankPaymentOrderSubmissionAction::items',
    // Schvalování manažerem střediska (F6) — interní, session-only v akci.
    'MyInvoice\\Action\\PurchaseInvoice\\Approval\\PurchaseInvoiceApprovalAction::forInvoice',
    'MyInvoice\\Action\\PurchaseInvoice\\Approval\\PurchaseInvoiceApprovalAction::request',
    'MyInvoice\\Action\\PurchaseInvoice\\Approval\\PurchaseInvoiceApprovalAction::cancel',
    // Přehled firem ve správě firem - interní, akce odmítá bearer token.
    'MyInvoice\\Action\\Settings\\SettingsAction::supplierDirectory',
    // Náhled převzetí AI a certifikátu do nové firmy - interní, akce odmítá bearer token.
    'MyInvoice\\Action\\Settings\\SupplierCarryOverAction::__invoke',
    // Automatický stav tachometru — přihlášení u služby je osobní krok, akce odmítá bearer token.
    'MyInvoice\\Action\\Logbook\\OdometerSourceAction::catalog',
    'MyInvoice\\Action\\Logbook\\OdometerSourceAction::status',
    'MyInvoice\\Action\\Logbook\\OdometerSourceAction::connect',
    'MyInvoice\\Action\\Logbook\\OdometerSourceAction::continue',
    'MyInvoice\\Action\\Logbook\\OdometerSourceAction::attach',
    'MyInvoice\\Action\\Logbook\\OdometerSourceAction::selectVehicle',
    'MyInvoice\\Action\\Logbook\\OdometerSourceAction::sync',
    'MyInvoice\\Action\\Logbook\\OdometerSourceAction::disconnect',
    // EET 2.0 — aplikační API webu a budoucího mobilu, mimo veřejný read-only subset;
    // kontrakt je v api/openapi-app-eet.yaml.
    'MyInvoice\\Action\\Eet\\EetCapabilitiesAction::__invoke',
    'MyInvoice\\Action\\Eet\\EetSettingsAction::show',
    'MyInvoice\\Action\\Eet\\EetSettingsAction::update',
    'MyInvoice\\Action\\Eet\\EetUnitsAction::list',
    'MyInvoice\\Action\\Eet\\EetUnitsAction::create',
    'MyInvoice\\Action\\Eet\\EetUnitsAction::update',
    'MyInvoice\\Action\\Eet\\EetUnitsAction::endValidity',
    'MyInvoice\\Action\\Eet\\EetDevicesAction::list',
    'MyInvoice\\Action\\Eet\\EetDevicesAction::create',
    'MyInvoice\\Action\\Eet\\EetDevicesAction::update',
    'MyInvoice\\Action\\Eet\\EetDevicesAction::deactivate',
    'MyInvoice\\Action\\Eet\\EetCertificatesAction::list',
    'MyInvoice\\Action\\Eet\\EetCertificatesAction::upload',
    'MyInvoice\\Action\\Eet\\EetCertificatesAction::deactivate',
    'MyInvoice\\Action\\Eet\\EetDiagnosticsAction::__invoke',
    'MyInvoice\\Action\\Eet\\EetPaymentAction::preview',
    'MyInvoice\\Action\\Eet\\EetPaymentAction::accept',
    'MyInvoice\\Action\\Eet\\EetPaymentAction::detail',
    'MyInvoice\\Action\\Eet\\EetPaymentAction::createIntent',
    'MyInvoice\\Action\\Eet\\EetPaymentAction::confirmIntent',
    'MyInvoice\\Action\\Eet\\EetPaymentAction::cancelIntent',
    'MyInvoice\\Action\\Eet\\EetRecordAction::list',
    'MyInvoice\\Action\\Eet\\EetRecordAction::detail',
    'MyInvoice\\Action\\Eet\\EetRecordAction::retry',
    'MyInvoice\\Action\\Eet\\EetRecordAction::correctionPreview',
    'MyInvoice\\Action\\Eet\\EetRecordAction::correction',
    'MyInvoice\\Action\\Eet\\EetRecordAction::queue',
    'MyInvoice\\Action\\Eet\\EetRecordAction::summary',
    'MyInvoice\\Action\\Eet\\EetRecordAction::export',
    'MyInvoice\\Action\\Eet\\EetRecordAction::invoice',
    // Oblíbené stránky uživatele - stav UI, akce je jen z webového rozhraní.
    'MyInvoice\\Action\\UserSettings\\FavoriteAction::list',
    'MyInvoice\\Action\\UserSettings\\FavoriteAction::create',
    'MyInvoice\\Action\\UserSettings\\FavoriteAction::update',
    'MyInvoice\\Action\\UserSettings\\FavoriteAction::delete',
];
$isSessionAction = static fn (array $route): bool => in_array($route['action'], $sessionActions, true);

// --- 3) Načti openapi.yaml -------------------------------------------------
$yaml = (string) file_get_contents($openapiFile);
// Mini parser: nepoužíváme Symfony Yaml (není v deps), stačí grep paths
preg_match_all('/^  (\/api\/v1\/[^:]+):/m', $yaml, $pm);
$specPaths = [];
foreach ($pm[1] as $p) {
    // Strip /api/v1 prefix → "/api/..." (aby šlo porovnat s routes)
    $normalized = '/api' . substr($p, strlen('/api/v1'));
    $specPaths[] = $normalized;
}

// Pro každý path zjistíme, jaké metody jsou v něm definované
$specByPath = [];
$lines = explode("\n", $yaml);
$currentPath = null;
foreach ($lines as $line) {
    if (preg_match('/^  (\/api\/v1\/[^:]+):/', $line, $m)) {
        $currentPath = '/api' . substr($m[1], strlen('/api/v1'));
        $specByPath[$currentPath] = [];
        continue;
    }
    if ($currentPath !== null && preg_match('/^    (get|post|put|patch|delete):/i', $line, $m)) {
        $specByPath[$currentPath][] = strtoupper($m[1]);
    }
    // Reset když narazíme na další top-level klíč (1 mezera nebo žádná)
    if (preg_match('/^[a-z]/', $line)) {
        $currentPath = null;
    }
}

// --- 4) Porovnání ---------------------------------------------------------
$missingInSpec = []; // route v kódu, chybí v specu
$staleInSpec   = []; // path v specu, chybí v kódu

foreach ($routes as $r) {
    if ($shouldSkip($r['path']))                     continue;
    if ($isSettingsMutation($r['method'], $r['path'])) continue;
    if ($r['method'] === 'ANY')                      continue; // 404 fallback
    if (!$isPublic($r['method'], $r['path']))          continue;
    if ($isCodebookMutation($r))                      continue;
    if ($isSessionAction($r))                         continue;

    $found = false;
    foreach ($specByPath as $path => $methods) {
        if (in_array($r['method'], $methods, true) && ($path === $r['path'] || $path === $r['template'])) {
            $found = true;
            break;
        }
    }
    if (!$found) {
        $missingInSpec[] = $r['method'] . ' ' . $r['path'];
    }
}

// Routes existing in code (any method), indexed by path
$codeByPath = [];
foreach ($routes as $r) {
    $codeByPath[$r['path']][] = $r['method'];
    $codeByPath[$r['template']][] = $r['method'];
}
foreach ($specByPath as $path => $methods) {
    $codeMethods = $codeByPath[$path] ?? [];
    if ($codeMethods === []) {
        $staleInSpec[] = '(no methods) ' . $path;
        continue;
    }
    foreach ($methods as $method) {
        if (!in_array($method, $codeMethods, true)) {
            $staleInSpec[] = $method . ' ' . $path;
        }
    }
}

// --- 5) Report -------------------------------------------------------------
$has = static fn (array $a) => count($a) > 0;
$pad = static fn (string $s, int $n) => str_pad($s, $n);

echo "OpenAPI ↔ routes coverage\n";
echo "==========================\n";
echo "Routes scanned (after filters): " . count(array_filter(
    $routes,
    static fn ($r) => !$shouldSkip($r['path']) && !$isSettingsMutation($r['method'], $r['path'])
        && $r['method'] !== 'ANY' && $isPublic($r['method'], $r['path']) && !$isCodebookMutation($r)
        && !$isSessionAction($r)
)) . "\n";
echo "Spec paths: " . count($specPaths) . " (each may have multiple methods)\n\n";

if (!$has($missingInSpec) && !$has($staleInSpec)) {
    echo "✓ No drift.\n";
    exit(0);
}

if ($has($missingInSpec)) {
    echo "Missing in openapi.yaml (" . count($missingInSpec) . "):\n";
    foreach ($missingInSpec as $row) echo "  - $row\n";
    echo "\n";
}
if ($has($staleInSpec)) {
    echo "Stale in openapi.yaml — not in code (" . count($staleInSpec) . "):\n";
    foreach ($staleInSpec as $row) echo "  - $row\n";
    echo "\n";
}

echo "Exit 1 — drift detected (warning only, CI nezablokuje).\n";
exit(1);
