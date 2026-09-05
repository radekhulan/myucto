<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Http\RequestPath;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\PermissionMiddleware;
use MyInvoice\Routes;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Security\PermissionCatalog;
use MyInvoice\Security\PermissionChecker;
use MyInvoice\Security\PermissionResolver;
use MyInvoice\Security\RoutePermissionMap;
use MyInvoice\Service\Tenant\SupplierAccess;
use MyInvoice\Service\Tenant\SupplierAccessResolver;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * R1 — jádro opravy: žádná registrovaná routa se nesmí percent-encodingem
 * degradovat na slabší autorizační pravidlo.
 *
 * Middleware běží PŘED RoutingMiddleware a `Slim\Psr7\Uri::filterPath()`
 * percent-encoding zachovává, kdežto `Slim\Routing\RouteResolver` si před
 * dispatchem volá rawurldecode(). Dokud PermissionMiddleware matchoval syrovou
 * cestu, escapovala se konkrétní pravidla pryč a ACL spadla na hrubší modulový
 * catch-all — router přitom doručil tutéž Action (262 z 968 dvojic metoda+cesta).
 *
 * Sweep escapuje PRVNÍ ZNAK KAŽDÉHO SEGMENTU a ověřuje, že
 *   a) RoutePermissionMap vrátí pro obě podoby stejný kind + klíč + level,
 *   b) PermissionMiddleware dojde k témuž rozhodnutí (tj. cestu skutečně normalizuje).
 */
final class RoutePermissionPathEscapeSweepTest extends TestCase
{
    private EffectiveRole $role;
    private SupplierAccess $access;

    /** Kind + klíč + level musí být pro escapovanou cestu identické. */
    public function testEveryRouteResolvesToTheSamePolicyWhenPercentEncoded(): void
    {
        $map = new RoutePermissionMap();
        $mismatched = [];

        foreach (self::routePairs() as [$method, $pattern, $clean, $variants]) {
            $expected = $map->match($method, $clean);
            if ($expected === null) continue; // pokrývá RoutePermissionCoverageTest

            foreach ($variants as $escaped) {
                $actual = $map->match($method, RequestPath::normalize($escaped));
                if ($actual === null
                    || $actual->kind !== $expected->kind
                    || $actual->key !== $expected->key
                    || $actual->minimum !== $expected->minimum
                ) {
                    $mismatched[$method . ' ' . $pattern . ' → ' . $escaped] = [
                        'clean'   => self::describe($expected),
                        'escaped' => self::describe($actual),
                    ];
                }
            }
        }

        self::assertSame([], $mismatched, 'Percent-encoding nesmí změnit autorizační pravidlo routy.');
    }

    /** A totéž na úrovni middleware — ten cestu musí normalizovat, ne jen mapa. */
    public function testPermissionMiddlewareDecidesIdenticallyForPercentEncodedPaths(): void
    {
        $map = new RoutePermissionMap();
        $catalog = new PermissionCatalog();
        $middleware = $this->middleware($map, $catalog);
        $failures = [];

        foreach (self::routePairs() as [$method, $pattern, $clean, $variants]) {
            $policy = $map->match($method, $clean);
            if ($policy === null) continue;

            if ($policy->kind === RoutePermissionMap::SUPERADMIN) {
                foreach ($variants as $escaped) {
                    $routeKey = $method . ' ' . $pattern . ' → ' . $escaped;
                    // Escapovaná admin cesta nesmí staff roli pustit dál…
                    $this->role = new EffectiveRole(2, 'Staff', 'staff', true, []);
                    $this->access = new SupplierAccess(0, true, null);
                    if ($this->statusFor($middleware, $method, $escaped) !== 403) {
                        $failures[$routeKey] = 'staff prošel na escapované superadmin cestě';
                        continue;
                    }
                    // …a superadmina naopak zablokovat nesmí.
                    $this->role = new EffectiveRole(1, 'Superadmin', 'superadmin', true, [], 'superadmin');
                    $this->access = new SupplierAccess(0, false, null);
                    if ($this->statusFor($middleware, $method, $escaped) !== 204) {
                        $failures[$routeKey] = 'superadmin neprošel na escapované cestě';
                    }
                }
                continue;
            }

            $this->access = new SupplierAccess(1, false, null);

            if ($policy->kind === RoutePermissionMap::ADMIN_PLUS) {
                $this->role = new EffectiveRole(3, 'Admin Plus', 'staff', true, [], 'admin_plus');
            } elseif ($policy->kind === RoutePermissionMap::PERMISSION) {
                $key = (string) $policy->key;
                if (!$catalog->has($key)) {
                    $failures[$method . ' ' . $pattern] = "klíč '$key' není v PermissionCatalog";
                    continue;
                }
                $type = $catalog->allowsRoleType($key, 'staff') ? 'staff' : 'client';
                // Role drží PRÁVĚ TEN konkrétní klíč — na hrubší catch-all by nedosáhla.
                $this->role = new EffectiveRole(2, 'Test', $type, true, [$key => $policy->minimum->value]);
            } else {
                $this->role = new EffectiveRole(2, 'Test', 'staff', true, []);
            }

            $cleanStatus = $this->statusFor($middleware, $method, $clean);
            foreach ($variants as $escaped) {
                $escapedStatus = $this->statusFor($middleware, $method, $escaped);
                if ($cleanStatus !== 204 || $escapedStatus !== 204) {
                    $failures[$method . ' ' . $pattern . ' → ' . $escaped]
                        = "clean=$cleanStatus escaped=$escapedStatus (oba musí být 204)";
                }
            }
        }

        self::assertSame([], $failures, 'PermissionMiddleware musí rozhodnout stejně pro escapovanou i čistou cestu.');
    }

    /** @return list<array{0:string,1:string,2:string,3:list<string>}> method, pattern, clean, escapované varianty */
    private static function routePairs(): array
    {
        static $pairs = null;
        if ($pairs !== null) return $pairs;

        $app = AppFactory::create();
        Routes::register($app);

        $pairs = [];
        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            $pattern = $route->getPattern();
            if (!str_starts_with($pattern, '/api/') || $pattern === '/api/{path:.*}') continue;
            $clean = self::examplePath($pattern);
            $variants = self::escapedVariants($clean);
            foreach ($route->getMethods() as $method) {
                if ($method === 'OPTIONS') continue;
                $pairs[] = [$method === 'HEAD' ? 'GET' : $method, $pattern, $clean, $variants];
            }
        }

        self::assertNotSame([], $pairs, 'Sweep nesmí zůstat prázdný — Routes::register() nic nezaregistroval.');

        return $pairs;
    }

    /**
     * Percent-escape prvního znaku KAŽDÉHO segmentu zvlášť (tak vzniká downgrade na
     * hrubší pravidlo) plus varianta se všemi segmenty naráz.
     *
     * @return list<string>
     */
    private static function escapedVariants(string $path): array
    {
        $segments = explode('/', $path);
        $variants = [];
        $all = $segments;

        foreach ($segments as $i => $segment) {
            if ($segment === '') continue;
            $encoded = '%' . strtoupper(bin2hex($segment[0])) . substr($segment, 1);
            $one = $segments;
            $one[$i] = $encoded;
            $variants[] = implode('/', $one);
            $all[$i] = $encoded;
        }
        $variants[] = implode('/', $all);

        return array_values(array_unique($variants));
    }

    private static function examplePath(string $pattern): string
    {
        return preg_replace_callback('/\{[^}:]+(?::([^}]+))?\}/', static function (array $match): string {
            $constraint = $match[1] ?? '';
            if ($constraint === '.*') return 'x';
            if (str_contains($constraint, '0-9') || str_contains($constraint, '\\d')) return '1';
            if (str_contains($constraint, '|')) return explode('|', trim($constraint, '()'))[0];
            return 'x';
        }, $pattern) ?? $pattern;
    }

    private static function describe(?object $policy): string
    {
        if (!$policy instanceof \MyInvoice\Security\RoutePermission) return 'null';
        return implode(':', [$policy->kind, $policy->key ?? '', (string) $policy->minimum->value]);
    }

    private function middleware(RoutePermissionMap $map, PermissionCatalog $catalog): PermissionMiddleware
    {
        // Jeden stub pro celý sweep; roli i scope přepínáme přes vlastnosti testu,
        // ať se pro ~1000 dvojic nestaví tisíce test doubles.
        $roles = $this->createStub(PermissionResolver::class);
        $roles->method('resolve')->willReturnCallback(fn (): EffectiveRole => $this->role);
        $roles->method('resolveDefault')->willReturnCallback(fn (): EffectiveRole => $this->role);
        $suppliers = $this->createStub(SupplierAccessResolver::class);
        $suppliers->method('resolve')->willReturnCallback(fn (): SupplierAccess => $this->access);

        return new PermissionMiddleware(
            new ResponseFactory(),
            $map,
            $roles,
            new PermissionChecker($catalog),
            $suppliers,
        );
    }

    private function statusFor(PermissionMiddleware $middleware, string $method, string $path): int
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost' . $path)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 5, 'role_id' => 2]);

        return $middleware->process($request, $this->handler())->getStatusCode();
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(204);
            }
        };
    }
}
