<?php

declare(strict_types=1);

namespace MyInvoice\Middleware;

use MyInvoice\Http\Json;
use MyInvoice\Http\RequestPath;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RoutePermissionMap;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

final class DemoReadOnlyMiddleware implements MiddlewareInterface
{
    public const ATTR_ENABLED = 'demo.enabled';

    private const ALLOWED_MUTATIONS = [
        'POST /api/auth/login',
        'POST /api/auth/logout',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly RoutePermissionMap $routes,
        private readonly ResponseFactory $responseFactory,
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        // Demo režim ve spravované zákaznické instalaci nemá co dělat; zámek sedí
        // tady, u vynucení, ne jen u zobrazení — jinak by přestalo hlásit demo,
        // ale zápisy by dál blokoval.
        if (!(new \MyInvoice\Service\System\ManagedModeGuard($this->config))->effectiveFlag(
            \MyInvoice\Service\System\ManagedModeGuard::KEY_DEMO_ENABLED,
            (bool) $this->config->get('demo.enabled', false),
        )) {
            return $handler->handle($request);
        }

        $request = $request->withAttribute(self::ATTR_ENABLED, true);
        $method = strtoupper($request->getMethod());
        // Normalizovaná cesta (viz RequestPath) — demo výjimky i RoutePermissionMap
        // musí matchovat totéž, co doručí router.
        $path = RequestPath::normalize($request->getUri()->getPath());

        // Demo ochrana předpokládá safe-method semantiku. Každý endpoint, který by
        // při GET zapisoval, musí mít vlastní demo větev bez persistence.
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $handler->handle($request);
        }

        if (in_array($method . ' ' . $path, self::ALLOWED_MUTATIONS, true)) {
            return $handler->handle($request);
        }

        $route = $this->routes->match($method, $path);
        if ($route !== null
            && $path !== '/api/catalog/exports'
            && $route->kind === RoutePermissionMap::PERMISSION
            && $route->minimum === AccessLevel::READ
        ) {
            return $handler->handle($request);
        }

        return Json::error(
            $this->responseFactory->createResponse(403),
            'demo_read_only',
            'Demo režim umožňuje funkce vyzkoušet, změny se ale neukládají.',
            403,
        );
    }

    public static function enabled(Request $request): bool
    {
        return $request->getAttribute(self::ATTR_ENABLED) === true;
    }
}
