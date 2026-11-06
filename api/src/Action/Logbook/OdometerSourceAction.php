<?php

declare(strict_types=1);

namespace MyInvoice\Action\Logbook;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\CarRepository;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Logbook\OdometerSource\OdometerSourceException;
use MyInvoice\Service\Logbook\OdometerSource\OdometerSourceService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Automatický stav tachometru ze zdrojů (automobilky, služby). Napojení je účet u zdroje,
 * auta se k němu přiřazují:
 *   GET    /api/logbook/odometer-sources                           katalog zdrojů a jejich formulářů
 *   GET    /api/logbook/cars/{id}/odometer-source                  přiřazení auta, jeho účet a připojené účty firmy
 *   POST   /api/logbook/cars/{id}/odometer-source/connect          {provider, input, account_id?} → krok připojení
 *   POST   /api/logbook/cars/{id}/odometer-source/connect/continue {input?} — dokončil uživatel přihlášení?
 *   POST   /api/logbook/cars/{id}/odometer-source/attach           {account_id} — přiřadit k připojenému účtu
 *   POST   /api/logbook/cars/{id}/odometer-source/vehicle          {account_id, vehicle_ref} — výběr auta z účtu
 *   POST   /api/logbook/cars/{id}/odometer-source/sync             načíst stav teď
 *   DELETE /api/logbook/cars/{id}/odometer-source                  odpojit auto (stavy tachometru zůstanou)
 *
 * Jen přihlášený uživatel v prohlížeči (ne API token): přihlášení u služby je osobní krok.
 * Změny vyžadují {@see RequestAuthorization::canManageOdometerSources()}.
 */
final class OdometerSourceAction
{
    public function __construct(
        private readonly OdometerSourceService $service,
        private readonly CarRepository $cars,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function catalog(Request $request, Response $response): Response
    {
        return $this->denied($request, $response, false)
            ?? Json::ok($response, $this->service->catalog())->withHeader('Cache-Control', 'no-store');
    }

    public function status(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, $args, false, fn (int $supplierId, int $carId) =>
            Json::ok($response, $this->service->status($supplierId, $carId)));
    }

    public function connect(Request $request, Response $response, array $args): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        return $this->run($request, $response, $args, true, function (int $supplierId, int $carId) use ($request, $response, $body) {
            $accountId = self::positiveInt($body['account_id'] ?? null);
            $result = $this->service->startConnect($supplierId, $carId, (string) ($body['provider'] ?? ''),
                is_array($body['input'] ?? null) ? $body['input'] : [], $this->userId($request), $accountId);
            $this->log($request, 'odometer_source.connect_started', $carId,
                ['provider' => (string) ($body['provider'] ?? ''), 'account_id' => $accountId]);
            return Json::ok($response, $result, 201);
        });
    }

    public function continue(Request $request, Response $response, array $args): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        return $this->run($request, $response, $args, true, function (int $supplierId, int $carId) use ($request, $response, $body) {
            $result = $this->service->continueConnect($supplierId, $carId, is_array($body['input'] ?? null) ? $body['input'] : [],
                $this->userId($request));
            if ($result['state'] !== 'waiting') $this->log($request, 'odometer_source.connected', $carId);
            return Json::ok($response, $result);
        });
    }

    public function attach(Request $request, Response $response, array $args): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        return $this->run($request, $response, $args, true, function (int $supplierId, int $carId) use ($request, $response, $body) {
            $accountId = self::positiveInt($body['account_id'] ?? null)
                ?? throw new OdometerSourceException(OdometerSourceException::INVALID_INPUT, 'Vyberte připojený účet.');
            $result = $this->service->attach($supplierId, $carId, $accountId, $this->userId($request));
            $this->log($request, 'odometer_source.attached', $carId, ['account_id' => $accountId]);
            return Json::ok($response, $result);
        });
    }

    public function selectVehicle(Request $request, Response $response, array $args): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        return $this->run($request, $response, $args, true, function (int $supplierId, int $carId) use ($request, $response, $body) {
            $accountId = self::positiveInt($body['account_id'] ?? null)
                ?? throw new OdometerSourceException(OdometerSourceException::INVALID_INPUT, 'Chybí připojený účet.');
            $result = $this->service->selectVehicle($supplierId, $carId, $accountId, (string) ($body['vehicle_ref'] ?? ''),
                $this->userId($request));
            $this->log($request, 'odometer_source.vehicle_selected', $carId, ['account_id' => $accountId]);
            return Json::ok($response, $result);
        });
    }

    public function sync(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, $args, true, fn (int $supplierId, int $carId) =>
            Json::ok($response, ['reading' => $this->service->sync($supplierId, $carId)] + $this->service->status($supplierId, $carId)));
    }

    public function disconnect(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, $args, true, function (int $supplierId, int $carId) use ($request, $response) {
            if (!$this->service->disconnect($supplierId, $carId)) {
                return Json::error($response, 'not_found', 'Auto není připojené ke zdroji stavu tachometru.', 404);
            }
            $this->log($request, 'odometer_source.disconnected', $carId);
            return Json::ok($response, ['disconnected' => true]);
        });
    }

    /** @param callable(int,int):Response $handler */
    private function run(Request $request, Response $response, array $args, bool $write, callable $handler): Response
    {
        $denied = $this->denied($request, $response, $write);
        if ($denied !== null) return $denied;
        $supplierId = SupplierGuard::currentId($request);
        $carId = (int) ($args['id'] ?? 0);
        if ($this->cars->find($carId, $supplierId) === null) {
            return Json::error($response, 'not_found', 'Auto nenalezeno.', 404);
        }
        try {
            return $handler($supplierId, $carId)->withHeader('Cache-Control', 'no-store');
        } catch (OdometerSourceException $e) {
            $status = match ($e->errorCode) {
                OdometerSourceException::INVALID_INPUT, OdometerSourceException::UNKNOWN_PROVIDER => 400,
                'not_connected', 'no_pending_connect', 'vehicle_taken' => 409,
                OdometerSourceException::RATE_LIMITED => 429,
                OdometerSourceException::REMOTE_UNAVAILABLE => 502,
                'connection_busy' => 423,
                default => 422,
            };
            return Json::error($response, $e->errorCode, $e->getMessage(), $status)->withHeader('Cache-Control', 'no-store');
        }
    }

    private function denied(Request $request, Response $response, bool $write): ?Response
    {
        if (!RequestAuthorization::isSessionAuth($request)) {
            return Json::error($response, 'forbidden', 'Automatický stav tachometru lze spravovat jen po přihlášení v aplikaci.', 403);
        }
        if ($write && !RequestAuthorization::canManageOdometerSources($request)) {
            return Json::error($response, 'forbidden', 'Na správu automatického stavu tachometru nemáte oprávnění.', 403);
        }
        return null;
    }

    private static function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function userId(Request $request): ?int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return (int) ($user['id'] ?? 0) ?: null;
    }

    private function log(Request $request, string $action, int $carId, array $payload = []): void
    {
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log($action, $this->userId($request), 'car', $carId, $payload, $ip, $request->getHeaderLine('User-Agent'));
    }
}
