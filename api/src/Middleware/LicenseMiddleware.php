<?php

declare(strict_types=1);

namespace MyInvoice\Middleware;

use MyInvoice\Http\Json;
use MyInvoice\Http\RequestPath;
use MyInvoice\Service\License\LicenseService;
use MyInvoice\Service\License\LicenseState;
use MyInvoice\Service\License\CommercialFeatureAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Licenční brána (E4). Běží těsně za AuthMiddleware:
 *   1. Po autentizaci spustí denní obnovu tokenu (mutex uvnitř LicenseService).
 *   2. Vypočtený stav uloží do request atributu (čtou ho Actions + /auth/me).
 *   3. V degradovaném / prošlém-trial stavu ponechá MIT základ plně funkční,
 *      ale zablokuje komerční účetní moduly i pro čtení.
 */
final class LicenseMiddleware implements MiddlewareInterface
{
    public const ATTR_STATE = 'license.state';

    public function __construct(
        private readonly LicenseService $license,
        private readonly ResponseFactory $responseFactory,
        private readonly LoggerInterface $logger,
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        $method = strtoupper($request->getMethod());

        // Denní obnova jen pro přihlášené requesty (má smysl a šetří síť u anonymních).
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if ((int) ($user['id'] ?? 0) > 0) {
            try {
                $this->license->renewIfDue();
            } catch (\Throwable $e) {
                // Obnova nikdy nesmí shodit request — jen zaloguj.
                $this->logger->error('license.renew.failed', ['error' => $e->getMessage()]);
            }
        }

        try {
            $state = $this->license->current();
        } catch (\Throwable $e) {
            // Když stav nejde spočítat (DB výpadek při startu), nech request projít.
            $this->logger->error('license.state.failed', ['error' => $e->getMessage()]);
            return $handler->handle($request);
        }

        $request = $request->withAttribute(self::ATTR_STATE, $state);

        if ($method === 'OPTIONS') {
            return $handler->handle($request);
        }

        // Normalizovaná cesta (jedno rawurldecode jako router) — jinak by
        // `/api/%61ccounting/...` restrictsApiPath() minulo a licenční brána se přeskočila.
        $path = RequestPath::normalize($request->getUri()->getPath());
        if (CommercialFeatureAccess::restrictsPayrollPath($path)) {
            if ($state->hasPayrollFeatures()) {
                return $handler->handle($request);
            }

            return Json::error(
                $this->responseFactory->createResponse(403),
                'license_payroll_feature_unavailable',
                'Modul Mzdy vyžaduje aktivní mzdový doplněk. Po skončení zkušební doby jej aktivujte na obrazovce Licence a hosting.',
                403,
                ['buy_url' => '/activation/purchase#payroll-addon'],
            );
        }

        if ($state->hasCommercialFeatures()) {
            return $handler->handle($request);
        }

        if (!CommercialFeatureAccess::restrictsApiPath($path)) {
            return $handler->handle($request);
        }

        return Json::error(
            $this->responseFactory->createResponse(403),
            'license_commercial_feature_unavailable',
            'Tato účetní funkce vyžaduje aktivní komerční licenci MyÚčto. Bezplatné funkce převzaté z MyInvoice zůstávají plně dostupné.',
            403,
        );
    }

    public static function state(Request $request): ?LicenseState
    {
        $state = $request->getAttribute(self::ATTR_STATE);
        return $state instanceof LicenseState ? $state : null;
    }
}
