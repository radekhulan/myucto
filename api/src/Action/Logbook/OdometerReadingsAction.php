<?php

declare(strict_types=1);

namespace MyInvoice\Action\Logbook;

use MyInvoice\Http\IsoDate;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\CarRepository;
use MyInvoice\Repository\OdometerReadingRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Stavy tachometru (podklad pro km k doplnění):
 *   GET    /api/logbook/odometer-readings        (?car_id=)
 *   POST   /api/logbook/odometer-readings        — zápis; týž den a zdroj stav přepíše
 *   DELETE /api/logbook/odometer-readings/{id}
 */
final class OdometerReadingsAction
{
    public function __construct(
        private readonly OdometerReadingRepository $repo,
        private readonly CarRepository $cars,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $carId = (int) ($request->getQueryParams()['car_id'] ?? 0) ?: null;
        return Json::ok($response, $this->repo->listForTenant($supplierId, $carId));
    }

    public function create(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $body = (array) ($request->getParsedBody() ?? []);

        $carId = (int) ($body['car_id'] ?? 0);
        if ($carId <= 0 || $this->cars->find($carId, $supplierId) === null) {
            return Json::error($response, 'validation_failed', 'Vyberte auto.', 400);
        }
        $date = trim((string) ($body['reading_date'] ?? ''));
        if (!IsoDate::isValid($date)) {
            return Json::error($response, 'validation_failed', 'Neplatné datum (formát YYYY-MM-DD).', 400);
        }
        $time = trim((string) ($body['reading_time'] ?? ''));
        if ($time !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $time)) {
            return Json::error($response, 'validation_failed', 'Neplatný čas (formát HH:MM).', 400);
        }
        $raw = $body['odometer'] ?? null;
        if ($raw === null || $raw === '' || !is_numeric($raw) || (float) $raw < 0) {
            return Json::error($response, 'validation_failed', 'Stav tachometru musí být nezáporné číslo.', 400);
        }
        $odometer = (int) round((float) $raw);

        $bounds = $this->repo->bounds($supplierId, $carId, $date);
        if (($bounds['before'] !== null && $odometer < $bounds['before'])
            || ($bounds['after'] !== null && $odometer > $bounds['after'])) {
            return Json::error($response, 'odometer_regression',
                'Stav tachometru nenavazuje na dřívější a pozdější stavy auta.', 422, $bounds);
        }

        $data = ['car_id' => $carId, 'reading_date' => $date, 'reading_time' => $time,
            'odometer' => $odometer, 'source' => 'manual', 'note' => $body['note'] ?? null];
        $id = $this->repo->upsert($supplierId, $data, $this->userId($request));
        $this->log($request, 'odometer_reading.saved', $id, $data);
        return Json::ok($response, $this->repo->find($id, $supplierId), 201);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        $reading = $this->repo->find($id, $supplierId);
        if ($reading === null || !$this->repo->delete($id, $supplierId)) {
            return Json::error($response, 'not_found', 'Stav tachometru nenalezen.', 404);
        }
        $this->log($request, 'odometer_reading.deleted', $id, $reading);
        return Json::ok($response, ['deleted' => true]);
    }

    private function userId(Request $request): ?int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return (int) ($user['id'] ?? 0) ?: null;
    }

    private function log(Request $request, string $action, int $id, array $payload): void
    {
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log($action, $this->userId($request), 'odometer_reading', $id, $payload, $ip, $request->getHeaderLine('User-Agent'));
    }
}
